<?php

namespace App\Domain\Scheduling\Support;

use App\Models\Appointment;

/** The facts about an appointment worth keeping in the audit trail (no free text). */
final class AppointmentSnapshot
{
    /** @return array<string, mixed> */
    public static function of(Appointment $appointment): array
    {
        $attributes = $appointment->getAttributes();

        return [
            'status' => $attributes['status'] ?? null,
            'client_id' => $attributes['client_id'] ?? null,
            'service_id' => $attributes['service_id'] ?? null,
            'clinician_membership_id' => $attributes['clinician_membership_id'] ?? null,
            'location_id' => $attributes['location_id'] ?? null,
            'modality' => $attributes['modality'] ?? null,
            'starts_at' => $appointment->starts_at,
            'ends_at' => $appointment->ends_at,
            'timezone' => $attributes['timezone'] ?? null,
            'source' => $attributes['source'] ?? null,
            'allow_overlap' => (bool) ($attributes['allow_overlap'] ?? false),
            'price_minor' => isset($attributes['price_minor']) ? (int) $attributes['price_minor'] : null,
            'currency' => $attributes['currency'] ?? null,
            'record_environment' => $attributes['record_environment'] ?? null,
            'rescheduled_from_id' => $attributes['rescheduled_from_id'] ?? null,
        ];
    }
}
