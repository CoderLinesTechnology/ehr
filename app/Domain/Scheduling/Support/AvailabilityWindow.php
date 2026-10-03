<?php

namespace App\Domain\Scheduling\Support;

use App\Domain\Scheduling\Modality;

/**
 * One occurrence of an availability rule for one way of meeting: the clinician
 * is bookable for $modality at $locationId (null = telehealth) between two
 * UTC timestamps. Slots are aligned in $ruleTimezone (where the rule's
 * wall-clock times live) and displayed in $displayTimezone.
 */
final readonly class AvailabilityWindow
{
    public function __construct(
        public string $clinicianId,
        public ?string $locationId,
        public Modality $modality,
        public string $ruleTimezone,
        public string $displayTimezone,
        public int $start,
        public int $end,
    ) {}
}
