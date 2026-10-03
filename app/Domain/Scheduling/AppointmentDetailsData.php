<?php

namespace App\Domain\Scheduling;

use App\Models\Location;
use App\Models\User;

/**
 * Input for UpdateAppointmentDetails. The scheduling note is always applied
 * (null clears it). A null modality keeps the current one; a null location
 * keeps the current one for an in-person appointment (telehealth never has one).
 */
final readonly class AppointmentDetailsData
{
    public function __construct(
        public ?string $schedulingNotes,
        public ?Modality $modality = null,
        public ?Location $location = null,
        public ?User $actor = null,
    ) {}
}
