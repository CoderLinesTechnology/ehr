<?php

namespace App\Domain\Clients;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantMismatch;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sensitive-access audit: opening a client's record (any tab of the profile)
 * writes `client.viewed`. Call it from the profile screens AFTER the
 * visibility check has passed, so only real disclosures are recorded.
 *
 * Staff move between a client's tabs constantly, so the same person opening
 * the same client again within WINDOW_MINUTES is not recorded again; the
 * de-duplication key (user + client) lives in the cache and is atomic, so two
 * tabs opened together record once.
 *
 * Fails closed: if the audit entry cannot be written the key is released and
 * the error propagates, so a failure never silences the next attempt. And a
 * cache outage errs towards auditing, never towards silence.
 */
final class RecordClientAccess
{
    public const WINDOW_MINUTES = 10;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /** @return bool whether an entry was written (false: already recorded in the window) */
    public function __invoke(Client $client, User $user, string $tab = 'overview'): bool
    {
        // A trail entry about another organization's client, written into that organization's audit log, must be impossible.
        if ($client->organization_id !== $this->tenant->id()) {
            throw new TenantMismatch('Refusing to record access to a client of another organization.');
        }

        $key = 'client-viewed:'.$user->getAuthIdentifier().':'.$client->getKey();

        // A cache outage must never mean a missing audit entry: when the cache cannot say "already
        // recorded", record again (more entries, never fewer).
        try {
            $first = Cache::add($key, true, now()->addMinutes(self::WINDOW_MINUTES));
        } catch (Throwable) {
            $first = true;
        }

        if (! $first) {
            return false;
        }

        try {
            $this->audit->record(
                'client.viewed',
                $client,
                metadata: array_filter(['tab' => $tab, 'environment' => $client->isDemo() ? 'demo' : null]),
                summary: 'Opened client record '.$client->formattedNumber(),
            );
        } catch (Throwable $e) {
            try {
                Cache::forget($key);
            } catch (Throwable) {
                // the cache is down as well: nothing to release
            }

            throw $e;
        }

        return true;
    }
}
