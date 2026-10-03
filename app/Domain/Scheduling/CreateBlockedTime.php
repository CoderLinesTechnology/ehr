<?php

namespace App\Domain\Scheduling;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Scheduling\Support\Text;
use App\Domain\Scheduling\Support\WallClock;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\BlockedTime;
use Illuminate\Support\Facades\DB;

/**
 * Blocks time for one clinician or everyone, at one location or all. All-day
 * blocks become local midnight → next local midnight in the location's
 * timezone (the organization's without one), so a day stays a day across DST.
 * Existing appointments are not touched; SlotFinder stops offering the time.
 */
final class CreateBlockedTime
{
    /** Longer blocks are almost always a typo (a year off). */
    public const MAX_DAYS = 366;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(BlockedTimeData $data): BlockedTime
    {
        $organization = $this->tenant->organizationOrFail();
        TenantGuard::assertOwned($organization->id, $data->membership, $data->location);

        $title = Text::optional($data->title, 120, 'title', 'title');

        if ($data->allDay) {
            if (! WallClock::isDate($data->startDate)) {
                throw new DomainException('Enter a valid start date.', 'invalid_date', 'start_date');
            }
            if (! WallClock::isDate($data->endDate)) {
                throw new DomainException('Enter a valid end date.', 'invalid_date', 'end_date');
            }
            if ($data->endDate < $data->startDate) {
                throw new DomainException('The end date cannot be before the start date.', 'invalid_range', 'end_date');
            }

            $timezone = $data->location?->timezone ?? $organization->timezone;
            $startsAt = WallClock::toUtc($data->startDate, '00:00', $timezone);
            $endsAt = WallClock::toUtc(WallClock::dateOf(WallClock::dayNumber($data->endDate) + 1), '00:00', $timezone);
        } else {
            [$startsAt, $endsAt] = [$data->startsAt, $data->endsAt];

            if ($endsAt->lessThanOrEqualTo($startsAt)) {
                throw new DomainException('The end must be after the start.', 'invalid_range', 'ends_at');
            }
        }

        if ($startsAt->diffInDays($endsAt) > self::MAX_DAYS) {
            throw new DomainException('Blocked time can cover at most a year at a time.', 'range_too_long', 'ends_at');
        }

        return DB::transaction(function () use ($data, $title, $startsAt, $endsAt) {
            $blockedTime = new BlockedTime;
            $blockedTime->fill([
                'membership_id' => $data->membership?->id,
                'location_id' => $data->location?->id,
                'kind' => $data->kind,
                'title' => $title,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'all_day' => $data->allDay,
            ]);
            $blockedTime->forceFill(['created_by_user_id' => $data->actor?->id])->save();

            $this->audit->record(
                'availability.blocked_time_created',
                subject: $blockedTime,
                after: self::snapshot($blockedTime),
                summary: "Blocked time added ({$data->kind->label()})",
            );

            return $blockedTime;
        });
    }

    /** @return array<string, mixed> */
    public static function snapshot(BlockedTime $blockedTime): array
    {
        $attributes = $blockedTime->getAttributes();

        return [
            'membership_id' => $attributes['membership_id'] ?? null,
            'location_id' => $attributes['location_id'] ?? null,
            'kind' => $attributes['kind'] ?? null,
            'title' => $attributes['title'] ?? null,
            'starts_at' => $blockedTime->starts_at,
            'ends_at' => $blockedTime->ends_at,
            'all_day' => (bool) ($attributes['all_day'] ?? false),
        ];
    }
}
