<?php

namespace App\Domain\Scheduling\Events;

use App\Models\Appointment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * $original (now "rescheduled") was replaced by $replacement, which links back
 * to it through rescheduled_from_id. Dispatched after commit.
 */
final class AppointmentRescheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Appointment $original,
        public readonly Appointment $replacement,
        public readonly ?string $reason,
        public readonly ?string $actorUserId,
    ) {}
}
