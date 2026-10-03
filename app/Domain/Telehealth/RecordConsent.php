<?php

namespace App\Domain\Telehealth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Shared\DomainException;
use App\Domain\Telehealth\Providers\ProviderRegistry;
use App\Domain\Telehealth\Providers\VideoServiceException;
use App\Domain\Tenancy\TenantContext;
use App\Models\TelehealthSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records that the client agreed (or no longer agrees) to the session being recorded. Recording is off unless
 * the organization allows it AND consent is recorded here; the database also refuses a recording or transcript
 * row for a session without consent. Consent cannot be withdrawn once a recording is stored (delete it first).
 *
 * Withdrawing consent while the call runs stops a cloud recording in progress at the vendor (after commit, best
 * effort); call passes issued from then on cannot record, and a recording that still arrives for the session is
 * deleted at the vendor by the webhook receiver instead of being stored.
 */
final class RecordConsent
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly TelehealthSettings $settings,
        private readonly ProviderRegistry $providers,
    ) {}

    public function __invoke(TelehealthSession $session, bool $consent, ?User $actor = null): TelehealthSession
    {
        $organization = $this->tenant->organizationOrFail();
        TenantGuard::assertOwned($organization->id, $session);

        return DB::transaction(function () use ($organization, $session, $consent, $actor) {
            /** @var TelehealthSession $locked */
            $locked = TelehealthSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($locked->consent_to_record === $consent) {
                $session->setRawAttributes($locked->getAttributes(), true);

                return $session;
            }
            if (in_array($locked->status, [SessionStatus::Cancelled, SessionStatus::Missed], true)) {
                throw new DomainException('Consent cannot be recorded for a session that did not take place.', 'session_closed', 'consent');
            }

            if ($consent) {
                if (! $this->settings->recordingEnabled($organization)) {
                    throw new DomainException('Recording is not enabled for your organization.', 'recording_disabled', 'consent');
                }
            } elseif ($locked->recordings()->exists() || $locked->transcripts()->exists()) {
                throw new DomainException('A recording or transcript of this session is already stored, so consent cannot be withdrawn.', 'consent_in_use', 'consent');
            }

            $locked->forceFill([
                'consent_to_record' => $consent,
                'consent_recorded_by_user_id' => $actor?->id,
                'consent_recorded_at' => now(),
            ])->save();

            $this->audit->record(
                $consent ? 'telehealth.consent_recorded' : 'telehealth.consent_withdrawn',
                subject: $locked,
                after: ['consent_to_record' => $consent],
                summary: $consent ? 'Client consent to record the telehealth session was recorded' : 'Client consent to record was withdrawn',
            );

            if (! $consent && $locked->status === SessionStatus::InProgress && $locked->provider_room_name !== null && ! $locked->isDemo()) {
                [$room, $providerKey] = [$locked->provider_room_name, $locked->provider_key];
                DB::afterCommit(fn () => $this->stopRecording($providerKey, $room));
            }

            $session->setRawAttributes($locked->getAttributes(), true);

            return $session;
        });
    }

    private function stopRecording(string $providerKey, string $room): void
    {
        try {
            $provider = $this->providers->get($providerKey);
            if ($provider->status()->usable()) {
                $provider->stopRecording($room);
            }
        } catch (VideoServiceException $e) {
            // Usually "nothing is being recorded". A recording that was running is refused when it arrives anyway.
            Log::info('Telehealth: no recording was stopped after consent was withdrawn', ['error' => $e->errorType]);
        }
    }
}
