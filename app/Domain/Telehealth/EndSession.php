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

/** Ends a running session: completed, with its duration, and the appointment completes with it. */
final class EndSession
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SessionTransition $transition,
        private readonly TransitionAppointment $appointments,
    ) {}

    public function __invoke(TelehealthSession $session, ?User $actor = null): TelehealthSession
    {
        TenantGuard::assertOwned($this->tenant->organizationOrFail()->id, $session);

        return DB::transaction(function () use ($session, $actor) {
            /** @var TelehealthSession $locked */
            $locked = TelehealthSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($locked->status === SessionStatus::Completed) {
                $session->setRawAttributes($locked->getAttributes(), true);

                return $session;
            }
            if ($locked->status !== SessionStatus::InProgress) {
                throw new DomainException('Only a session that is in progress can be ended.', 'session_not_running');
            }

            $now = now();
            $this->transition->apply($locked, SessionStatus::Completed, $actor?->id, null, [
                'ended_at' => $now,
                'duration_minutes' => (int) max(0, $locked->started_at->diffInMinutes($now)),
            ]);

            $appointment = Appointment::query()->findOrFail($locked->appointment_id);
            if ($appointment->status !== AppointmentStatus::Completed) {
                ($this->appointments)($appointment, AppointmentStatus::Completed, $actor);
            }

            $session->setRawAttributes($locked->getAttributes(), true);

            return $session;
        });
    }
}
