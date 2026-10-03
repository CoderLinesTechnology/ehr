<?php

namespace Database\Factories;

use App\Domain\Scheduling\BlockedTimeKind;
use App\Models\BlockedTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Requires a tenant context. Production code goes through CreateBlockedTime.
 * Default: blocks everyone, at every location, tomorrow 12:00–13:00 UTC.
 * (BlockedTime has no HasFactory trait: use BlockedTimeFactory::new().)
 *
 * @extends Factory<BlockedTime>
 */
class BlockedTimeFactory extends Factory
{
    protected $model = BlockedTime::class;

    public function definition(): array
    {
        $start = CarbonImmutable::now('UTC')->addDay()->setTime(12, 0);

        return [
            'membership_id' => null,
            'location_id' => null,
            'kind' => BlockedTimeKind::Blocked,
            'title' => null,
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            'all_day' => false,
        ];
    }

    /** Between two instants (stored as UTC). */
    public function between(DateTimeInterface $startsAt, DateTimeInterface $endsAt): static
    {
        return $this->state(fn () => [
            'starts_at' => CarbonImmutable::instance($startsAt)->utc(),
            'ends_at' => CarbonImmutable::instance($endsAt)->utc(),
        ]);
    }
}
