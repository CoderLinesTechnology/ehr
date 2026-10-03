<?php

namespace App\Domain\Telehealth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Shared\DomainException;
use App\Domain\Telehealth\Providers\MeetingRequest;
use App\Domain\Telehealth\Providers\ProviderRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Models\Appointment;
use App\Models\TelehealthSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** The clinician supplies (or replaces) the session's meeting link. Validated by the provider; the link is never audited. */
final class SetMeetingLink
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProviderRegistry $providers,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(TelehealthSession $session, string $url, ?User $actor = null): TelehealthSession
    {
        $organization = $this->tenant->organizationOrFail();
        TenantGuard::assertOwned($organization->id, $session);

        return DB::transaction(function () use ($organization, $session, $url, $actor) {
            /** @var TelehealthSession $locked */
            $locked = TelehealthSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! $locked->status->isOpen()) {
                throw new DomainException('The meeting link of a finished session can no longer be changed.', 'session_closed', 'join_url');
            }

            $appointment = Appointment::query()->findOrFail($locked->appointment_id);
            $details = $this->providers->get($locked->provider_key)->createMeeting(new MeetingRequest($organization, $appointment, $url));

            $had = $locked->join_url !== null;
            $locked->forceFill(['join_url' => $details->joinUrl])->save();

            $this->audit->record(
                'telehealth.link_set',
                subject: $locked,
                metadata: ['replaced' => $had],
                summary: 'Telehealth meeting link '.($had ? 'replaced' : 'added'),
            );

            $session->setRawAttributes($locked->getAttributes(), true);

            return $session;
        });
    }
}
