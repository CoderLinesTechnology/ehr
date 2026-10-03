<?php

namespace App\Domain\Telehealth\Listeners;

use App\Domain\Scheduling\Events\AppointmentRescheduled;
use App\Domain\Scheduling\Events\AppointmentScheduled;
use App\Domain\Scheduling\Events\AppointmentStatusChanged;
use App\Domain\Scheduling\Modality;
use App\Domain\Telehealth\SyncTelehealthSession;
use App\Domain\Tenancy\TenantContext;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\TelehealthSession;
use Closure;

/**
 * Projects scheduling events onto telehealth sessions: a telehealth appointment gets its session when booked,
 * the session follows its status (cancelled, no-show → missed, completed), and a reschedule creates the new
 * session, moves the video room to it (the client's link keeps working) and only then retires the old session —
 * in that order, because retiring a session deletes the room it still holds. Only telehealth appointments (and
 * sessions of appointments that were telehealth) are touched.
 */
final class SyncSessionWithAppointment
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SyncTelehealthSession $sync,
    ) {}

    public function handleScheduled(AppointmentScheduled $event): void
    {
        if ($event->appointment->modality !== Modality::Telehealth) {
            return;
        }

        $this->within($event->appointment, fn () => ($this->sync)($event->appointment, null, $event->actorUserId));
    }

    public function handleStatusChanged(AppointmentStatusChanged $event): void
    {
        if ($event->appointment->modality !== Modality::Telehealth) {
            return; // no query for in-person appointments; a session left behind by a modality change is reconciled on the sessions list
        }

        $this->within($event->appointment, fn () => ($this->sync)($event->appointment, $event->reason, $event->actorUserId));
    }

    public function handleRescheduled(AppointmentRescheduled $event): void
    {
        if ($event->original->modality !== Modality::Telehealth && $event->replacement->modality !== Modality::Telehealth) {
            return;
        }

        $this->within($event->replacement, function () use ($event) {
            $old = TelehealthSession::query()->where('appointment_id', $event->original->id)->first();

            if ($event->replacement->modality === Modality::Telehealth) {
                ($this->sync)($event->replacement, null, $event->actorUserId, $old);
            }

            ($this->sync)($event->original, 'Rescheduled', $event->actorUserId);
        });
    }

    /** Listeners run after commit, normally still inside the request's tenant; restore it if not. */
    private function within(Appointment $appointment, Closure $callback): void
    {
        if ($this->tenant->id() === $appointment->organization_id && ! $this->tenant->bypassing()) {
            $callback();

            return;
        }

        $this->tenant->runAs(Organization::query()->findOrFail($appointment->organization_id), $callback);
    }
}
