<?php

namespace Tests\Unit\Scheduling;

use App\Domain\Scheduling\Support\Intervals;
use App\Domain\Scheduling\Support\WallClock;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class IntervalsTest extends TestCase
{
    /** @return iterable<string, array{list<array{int, int}>, list<array{int, int}>}> */
    public static function subtractions(): iterable
    {
        // Window [100, 200).
        yield 'nothing busy' => [[], [[100, 200]]];
        yield 'busy in the middle' => [[[120, 150]], [[100, 120], [150, 200]]];
        yield 'busy at both edges' => [[[100, 110], [190, 200]], [[110, 190]]];
        yield 'busy outside the window' => [[[0, 100], [200, 300]], [[100, 200]]];
        yield 'overlapping, unsorted busy' => [[[160, 180], [120, 150], [140, 170]], [[100, 120], [180, 200]]];
        yield 'nested busy' => [[[110, 190], [120, 130]], [[100, 110], [190, 200]]];
        yield 'everything busy' => [[[50, 250]], []];
        yield 'touching busy blocks leave no sliver' => [[[120, 140], [140, 160]], [[100, 120], [160, 200]]];
    }

    #[Test]
    #[DataProvider('subtractions')]
    public function free_time_is_the_window_minus_busy_time(array $busy, array $free): void
    {
        $this->assertSame($free, Intervals::subtract(100, 200, $busy));
    }

    #[Test]
    public function starts_are_aligned_in_local_time_and_leave_room_for_the_duration(): void
    {
        $utc = new DateTimeZone('UTC');
        $at = fn (string $time) => WallClock::toUtc('2026-10-05', $time, 'UTC')->getTimestamp();
        $format = fn (array $starts) => array_map(fn (int $t) => gmdate('H:i', $t), $starts);

        $this->assertSame(['09:15', '09:30', '09:45'], $format(Intervals::alignedStarts($at('09:10'), $at('10:30'), 45 * 60, 15 * 60, $utc)));
        $this->assertSame(['09:15', '09:30'], $format(Intervals::alignedStarts($at('09:10'), $at('10:30'), 45 * 60, 15 * 60, $utc, notAfter: $at('09:30'))));
        $this->assertSame([], Intervals::alignedStarts($at('09:00'), $at('09:40'), 45 * 60, 15 * 60, $utc));
        $this->assertSame($at('09:15'), Intervals::alignUp($at('09:00') + 1, 15 * 60, $utc), 'A second past the grid rounds up.');

        // Alignment is to the local clock: 09:00 in Kathmandu (UTC+5:45) is 03:15 UTC.
        $kathmandu = new DateTimeZone('Asia/Kathmandu');
        $start = WallClock::toUtc('2026-10-05', '08:50', 'Asia/Kathmandu')->getTimestamp();
        $this->assertSame('03:15', gmdate('H:i', Intervals::alignUp($start, 60 * 60, $kathmandu)));
    }

    #[Test]
    public function a_half_hour_dst_shift_does_not_leave_starts_off_the_grid(): void
    {
        // Lord Howe Island moves from UTC+10:30 to UTC+11 at 02:00 on 4 Oct 2026.
        $zone = new DateTimeZone('Australia/Lord_Howe');
        $starts = Intervals::alignedStarts(
            WallClock::toUtc('2026-10-04', '00:00', 'Australia/Lord_Howe')->getTimestamp(),
            WallClock::toUtc('2026-10-04', '06:00', 'Australia/Lord_Howe')->getTimestamp(),
            3600, 3600, $zone,
        );

        $local = array_map(fn (int $t) => (new \DateTimeImmutable('@'.$t))->setTimezone($zone)->format('H:i'), $starts);
        $this->assertSame(['00:00', '01:00', '03:00', '04:00', '05:00'], $local);
    }
}
