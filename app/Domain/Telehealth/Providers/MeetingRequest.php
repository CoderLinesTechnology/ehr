<?php

namespace App\Domain\Telehealth\Providers;

use App\Models\Appointment;
use App\Models\Organization;

/** Input to MeetingProvider::createMeeting. $suppliedUrl is what the clinician typed (null → the organization default). */
final readonly class MeetingRequest
{
    public function __construct(
        public Organization $organization,
        public Appointment $appointment,
        public ?string $suppliedUrl = null,
    ) {}
}
