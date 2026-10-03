<?php

namespace App\Domain\Scheduling;

use App\Models\Location;
use App\Models\OrganizationMembership;

/**
 * Input for SaveAvailabilityRule. Times are wall-clock 'HH:MM' in the
 * location's timezone (the organization's when there is no location; only
 * telehealth rules may have none); '24:00' ends a window at midnight. Dates are
 * business dates 'Y-m-d'. $serviceIds restricts the rule to some of the
 * clinician's services (empty = every service they provide).
 */
final readonly class AvailabilityRuleData
{
    /** @param list<string> $serviceIds */
    public function __construct(
        public OrganizationMembership $membership,
        public int $weekday,
        public string $startTime,
        public string $endTime,
        public AvailabilityModality $modality,
        public string $effectiveFrom,
        public ?Location $location = null,
        public ?string $effectiveUntil = null,
        public int $repeatEveryWeeks = 1,
        public bool $isBookableOnline = true,
        public bool $isActive = true,
        public array $serviceIds = [],
    ) {}
}
