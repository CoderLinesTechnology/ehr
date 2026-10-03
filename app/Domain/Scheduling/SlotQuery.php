<?php

namespace App\Domain\Scheduling;

use App\Domain\Scheduling\Support\WallClock;
use App\Domain\Shared\DomainException;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Service;

/**
 * "When can this service be booked?" for SlotFinder.
 *
 * $from/$to are local business dates ('Y-m-d', inclusive, at most 31 days),
 * read in each availability rule's own timezone. A location narrows the
 * search to in-person slots there; with modality telehealth the location is
 * ignored (telehealth appointments have no location). $forOnlineBooking
 * applies what clients are bound by and staff are not: minimum notice,
 * maximum advance, and only rules and services marked bookable online.
 */
final readonly class SlotQuery
{
    public const MAX_DAYS = 31;

    public ?Location $location;

    public function __construct(
        public Service $service,
        public string $from,
        public string $to,
        public ?OrganizationMembership $clinician = null,
        ?Location $location = null,
        public ?Modality $modality = null,
        public bool $forOnlineBooking = false,
    ) {
        if (! WallClock::isDate($from)) {
            throw new DomainException('Choose a valid start date.', 'invalid_date', 'from');
        }

        if (! WallClock::isDate($to)) {
            throw new DomainException('Choose a valid end date.', 'invalid_date', 'to');
        }

        $days = $this->days();

        if ($days < 1) {
            throw new DomainException('The end date cannot be before the start date.', 'invalid_range', 'to');
        }

        if ($days > self::MAX_DAYS) {
            throw new DomainException('Search at most '.self::MAX_DAYS.' days at a time.', 'range_too_long', 'to');
        }

        $this->location = $modality === Modality::Telehealth ? null : $location;
    }

    /** Number of days in the range, inclusive. */
    public function days(): int
    {
        return WallClock::dayNumber($this->to) - WallClock::dayNumber($this->from) + 1;
    }
}
