<?php

namespace App\Domain\Scheduling\Support;

use App\Domain\Scheduling\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\User;

/** Writes one insert-only status-history row. Called only by the scheduling actions. */
final class AppointmentHistory
{
    public static function record(
        Appointment $appointment,
        ?AppointmentStatus $from,
        AppointmentStatus $to,
        ?string $reason,
        ?User $actor,
    ): AppointmentStatusHistory {
        $history = new AppointmentStatusHistory;
        $history->forceFill([
            'organization_id' => $appointment->organization_id,
            'appointment_id' => $appointment->id,
            'record_environment' => $appointment->record_environment,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'actor_user_id' => $actor?->id,
            'occurred_at' => now(),
        ])->save();

        return $history;
    }
}
