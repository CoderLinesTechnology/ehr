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
 * the session follows its status (cancelled, no-show → missed, completed), and a reschedule retires the old
 * session and creates the new one with the same meeting link. Only telehealth appointments (and sessions of
 * appointments that were telehealth) are touched.
 */
final class SyncSessionWithAppointment
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SyncTelehealthSession $sync,
    ) {}

    public function handleScheduled(AppointmentScheduled $event): void
    {
        $this->within($event->appointment, fn () => ($this->sync)($event->appointment, null, $event->actorUserId));
    }

    public function handleStatusChanged(AppointmentStatusChanged $event): void
    {
        $this->within($event->appointment, fn () => ($this->sync)($event->appointment, $event->reason, $event->actorUserId));
    }

    public function handleRescheduled(AppointmentRescheduled $event): void
    {
        $this->within($event->replacement, function () use ($event) {
            $old = TelehealthSession::query()->where('appointment_id', $event->original->id)->first();

            ($this->sync)($event->original, 'Rescheduled', $event->actorUserId);

            if ($event->replacement->modality === Modality::Telehealth) {
                ($this->sync)($event->replacement, null, $event->actorUserId, $old);
            }
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
