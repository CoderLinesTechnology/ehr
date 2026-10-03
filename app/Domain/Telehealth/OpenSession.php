<?php

namespace App\Domain\Telehealth;

use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Scheduling\TransitionAppointment;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Appointment;
use App\Models\TelehealthSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A staff member joins: the session (and its appointment) move to "in progress". Allowed only inside the join
 * window (the organization's "join opens N minutes before" until the appointment ends) and only while the
 * session is still open. Opening an already running session is a no-op.
 */
final class OpenSession
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SessionTransition $transition,
        private readonly TransitionAppointment $appointments,
        private readonly TelehealthSettings $settings,
    ) {}

    public function __invoke(TelehealthSession $session, ?User $actor = null): TelehealthSession
    {
        $organization = $this->tenant->organizationOrFail();
        TenantGuard::assertOwned($organization->id, $session);

        return DB::transaction(function () use ($organization, $session, $actor) {
            /** @var TelehealthSession $locked */
            $locked = TelehealthSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($locked->status !== SessionStatus::InProgress) {
                if (! $locked->status->canTransitionTo(SessionStatus::InProgress)) {
                    throw new DomainException('This session can no longer be joined.', 'session_closed');
                }

                $appointment = Appointment::query()->findOrFail($locked->appointment_id);
                $early = min($this->settings->joinEarlyMinutes($organization), TelehealthSettings::MAX_EARLY_MINUTES);
                if (! JoinWindow::isOpen($appointment->starts_at, $appointment->ends_at, $early, now())) {
                    throw new DomainException('This session can be joined from '.$early.' minutes before it starts until it ends.', 'outside_join_window');
                }

                $this->transition->apply($locked, SessionStatus::InProgress, $actor?->id, null, ['started_at' => now()]);

                if ($appointment->status !== AppointmentStatus::InProgress) {
                    ($this->appointments)($appointment, AppointmentStatus::InProgress, $actor);
                }
            }

            $session->setRawAttributes($locked->getAttributes(), true);

            return $session;
        });
    }
}
