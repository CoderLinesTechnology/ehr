<?php

namespace App\Domain\Scheduling;

use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Input for CreateBlockedTime: either a timed block between two instants, or
 * an all-day block over local business dates (inclusive), stored as local
 * midnight → the local midnight after the last day. A null membership blocks
 * everyone; a null location blocks every location.
 */
final readonly class BlockedTimeData
{
    private function __construct(
        public BlockedTimeKind $kind,
        public bool $allDay,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public ?string $startDate,
        public ?string $endDate,
        public ?OrganizationMembership $membership,
        public ?Location $location,
        public ?string $title,
        public ?User $actor,
    ) {}

    public static function timed(
        BlockedTimeKind $kind,
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
        ?OrganizationMembership $membership = null,
        ?Location $location = null,
        ?string $title = null,
        ?User $actor = null,
    ): self {
        return new self(
            $kind, false,
            CarbonImmutable::instance($startsAt)->utc(), CarbonImmutable::instance($endsAt)->utc(),
            null, null, $membership, $location, $title, $actor,
        );
    }

    /** $endDate (inclusive) defaults to $startDate: a single day. */
    public static function allDay(
        BlockedTimeKind $kind,
        string $startDate,
        ?string $endDate = null,
        ?OrganizationMembership $membership = null,
        ?Location $location = null,
        ?string $title = null,
        ?User $actor = null,
    ): self {
        return new self($kind, true, null, null, $startDate, $endDate ?? $startDate, $membership, $location, $title, $actor);
    }
}
