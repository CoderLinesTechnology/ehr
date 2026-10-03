<?php

namespace App\Domain\Telehealth;

use App\Domain\Telehealth\Providers\ProviderRegistry;
use App\Domain\Telehealth\Providers\VideoServiceException;
use App\Models\TelehealthSession;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deletes a finished session's room at the vendor once the transition that finished it has committed — which
 * also ejects anyone still in it. Best effort: no queue worker is needed, a failure is logged (error type only)
 * and never thrown, and the room's `exp` closes it anyway. The room is deleted only if the session STILL holds
 * it at that moment: a room that moved to the rescheduled session must survive. The name stays on the session
 * (the recording webhook arrives after the room is gone and finds the session by it).
 *
 * One instance per request: once the vendor proved unreachable (or failing), the rest of the request's releases are
 * skipped instead of each waiting for its own timeout — a reconcile retiring many sessions stays fast while the
 * vendor is down.
 */
#[Scoped]
final class ReleaseRoom
{
    private bool $vendorDown = false;

    public function __construct(private readonly ProviderRegistry $providers) {}

    public function afterCommit(TelehealthSession $session): void
    {
        $name = $session->provider_room_name;
        if ($name === null || $session->isDemo()) {
            return;
        }

        [$organizationId, $sessionId, $providerKey] = [$session->organization_id, $session->id, $session->provider_key];
        DB::afterCommit(fn () => $this->release($organizationId, $sessionId, $providerKey, $name));
    }

    public function release(string $organizationId, string $sessionId, string $providerKey, string $name): void
    {
        if ($this->vendorDown) {
            return;
        }

        try {
            $holds = DB::table('telehealth_sessions')
                ->where('organization_id', $organizationId)
                ->where('id', $sessionId)
                ->where('provider_room_name', $name)
                ->exists();
            $provider = $this->providers->get($providerKey);
            if (! $holds || ! $provider->status()->usable()) {
                return;
            }

            $provider->deleteRoom($name);
        } catch (Throwable $e) {
            $this->vendorDown = ! $e instanceof VideoServiceException || $e->status === 0 || $e->status >= 500 || $e->status === 429;
            Log::warning('Telehealth: the video room of a finished session could not be deleted; it expires by itself', [
                'session' => $sessionId,
                'error' => $e instanceof VideoServiceException ? $e->errorType : $e::class,
            ]);
        }
    }
}
