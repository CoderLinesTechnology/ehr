<?php

namespace App\Domain\Telehealth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Modality;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Shared\DomainException;
use App\Domain\Telehealth\Providers\MeetingRequest;
use App\Domain\Telehealth\Providers\ProviderRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Models\Appointment;
use App\Models\TelehealthSession;
use App\Models\TelehealthSessionStatusHistory;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Creates the session of a telehealth appointment (once: unique per appointment), copying the live/demo
 * environment, client and clinician from it and asking the provider for the meeting link (the organization's
 * default when none is supplied). Called by the scheduling listener; safe to call twice.
 */
final class EnsureTelehealthSession
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProviderRegistry $providers,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(Appointment $appointment, ?string $suppliedUrl = null, ?string $actorUserId = null): ?TelehealthSession
    {
        $organization = $this->tenant->organizationOrFail();
        TenantGuard::assertOwned($organization->id, $appointment);

        if ($appointment->modality !== Modality::Telehealth) {
            return null;
        }

        $existing = TelehealthSession::query()->where('appointment_id', $appointment->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $provider = $this->providers->default();

        try {
            $details = $provider->createMeeting(new MeetingRequest($organization, $appointment, $suppliedUrl));
        } catch (DomainException) {
            // An unacceptable supplied link must not stop the appointment having a session; it simply has no link yet.
            $details = $provider->createMeeting(new MeetingRequest($organization, $appointment, null));
        }

        try {
            $session = new TelehealthSession;
            $session->forceFill([
                'organization_id' => $organization->id,
                'record_environment' => $appointment->record_environment,
                'appointment_id' => $appointment->id,
                'client_id' => $appointment->client_id,
                'clinician_membership_id' => $appointment->clinician_membership_id,
                'status' => SessionStatus::Scheduled,
                'provider_key' => $provider->key(),
                'join_url' => $details->joinUrl,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            return TelehealthSession::query()->where('appointment_id', $appointment->id)->firstOrFail();
        }

        (new TelehealthSessionStatusHistory)->forceFill([
            'organization_id' => $session->organization_id,
            'record_environment' => $session->record_environment,
            'telehealth_session_id' => $session->id,
            'from_status' => null,
            'to_status' => SessionStatus::Scheduled,
            'actor_user_id' => $actorUserId,
            'occurred_at' => now(),
        ])->save();

        $this->audit->record(
            'telehealth.session_created',
            subject: $session,
            after: ['status' => SessionStatus::Scheduled->value, 'provider' => $session->provider_key, 'has_link' => $details->joinUrl !== null],
            summary: 'Telehealth session created for a telehealth appointment',
        );

        return $session;
    }
}
