<?php

namespace App\Domain\Scheduling\Events;

use App\Models\Appointment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new appointment was booked (not a reschedule: that fires
 * AppointmentRescheduled only, so subscribers never announce a move as a
 * fresh booking). Dispatched after commit.
 */
final class AppointmentScheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Appointment $appointment,
        public readonly ?string $actorUserId,
    ) {}
}
