<?php

namespace App\Domain\Scheduling\Events;

use App\Domain\Scheduling\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An appointment moved through its lifecycle (confirmed, checked in, started,
 * completed, cancelled, no-show). The move to "rescheduled" is announced by
 * AppointmentRescheduled instead. Dispatched after commit.
 */
final class AppointmentStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Appointment $appointment,
        public readonly AppointmentStatus $from,
        public readonly AppointmentStatus $to,
        public readonly ?string $reason,
        public readonly ?string $actorUserId,
    ) {}
}
