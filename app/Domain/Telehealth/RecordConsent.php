<?php

namespace App\Domain\Telehealth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\TelehealthSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records that the client agreed (or no longer agrees) to the session being recorded. Recording is off unless
 * the organization allows it AND consent is recorded here; the database also refuses a recording or transcript
 * row for a session without consent. Consent cannot be withdrawn once a recording is stored (delete it first).
 */
final class RecordConsent
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly TelehealthSettings $settings,
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

            $session->setRawAttributes($locked->getAttributes(), true);

            return $session;
        });
    }
}
