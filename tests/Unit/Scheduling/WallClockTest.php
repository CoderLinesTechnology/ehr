<?php

namespace Tests\Unit\Scheduling;

use App\Domain\Scheduling\Support\WallClock;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class WallClockTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string}> */
    public static function conversions(): iterable
    {
        yield 'no DST zone' => ['2026-10-06', '14:00', 'Africa/Accra', '2026-10-06 14:00:00'];
        yield 'east of UTC, previous UTC day' => ['2026-10-05', '09:00', 'Asia/Tokyo', '2026-10-05 00:00:00'];
        yield 'New York in winter' => ['2026-03-07', '09:00', 'America/New_York', '2026-03-07 14:00:00'];
        yield 'New York on spring-forward day' => ['2026-03-08', '09:00', 'America/New_York', '2026-03-08 13:00:00'];
        yield 'a time inside the spring-forward gap moves forward' => ['2026-03-08', '02:30', 'America/New_York', '2026-03-08 07:30:00'];
        yield 'an ambiguous fall-back time is its first occurrence' => ['2026-11-01', '01:30', 'America/New_York', '2026-11-01 05:30:00'];
        yield 'New York on fall-back day' => ['2026-11-01', '09:00', 'America/New_York', '2026-11-01 14:00:00'];
        yield '24:00 is the next local midnight' => ['2026-03-08', '24:00', 'America/New_York', '2026-03-09 04:00:00'];
        yield 'seconds are kept' => ['2026-10-06', '09:15:30', 'Africa/Accra', '2026-10-06 09:15:30'];
    }

    #[Test]
    #[DataProvider('conversions')]
    public function local_wall_clock_times_become_the_right_utc_instants(string $date, string $time, string $timezone, string $utc): void
    {
        $instant = WallClock::toUtc($date, $time, $timezone);

        $this->assertSame($utc, $instant->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $instant->getTimezone()->getName());
    }

    #[Test]
    #[DataProvider('conversions')]
    public function the_fast_path_agrees_with_carbon_create_on_every_case(string $date, string $time, string $timezone, string $utc): void
    {
        $timestamp = WallClock::timestamp($date, $time, new DateTimeZone($timezone));

        $this->assertSame(WallClock::toUtc($date, $time, $timezone)->getTimestamp(), $timestamp);
        $this->assertSame($utc, WallClock::instant($timestamp)->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', WallClock::instant($timestamp)->getTimezone()->getName());
    }

    #[Test]
    public function dates_and_times_are_validated_strictly(): void
    {
        foreach (['2026-02-28', '2024-02-29', '2026-12-31'] as $valid) {
            $this->assertTrue(WallClock::isDate($valid), $valid);
        }
        foreach (['2026-02-30', '2026-2-3', '26-01-01', '2026-13-01', '2026-10-06 00:00', ''] as $invalid) {
            $this->assertFalse(WallClock::isDate($invalid), $invalid);
        }
        foreach (['00:00', '09:00', '09:00:00', '23:59:59', '24:00', '24:00:00'] as $valid) {
            $this->assertTrue(WallClock::isTime($valid), $valid);
        }
        foreach (['24:01', '9:00', '12:60', '12:00:60', 'noon', ''] as $invalid) {
            $this->assertFalse(WallClock::isTime($invalid), $invalid);
        }

        $this->expectException(InvalidArgumentException::class);
        WallClock::toUtc('2026-02-30', '09:00', 'UTC');
    }

    #[Test]
    public function day_numbers_carry_calendar_arithmetic_without_timezones(): void
    {
        $this->assertSame(0, WallClock::dayNumber('1970-01-01'));
        $this->assertSame('2026-10-05', WallClock::dateOf(WallClock::dayNumber('2026-10-05')));
        $this->assertSame('2026-03-01', WallClock::dateOf(WallClock::dayNumber('2026-02-28') + 1));

        $this->assertSame(1, WallClock::isoWeekday(WallClock::dayNumber('2026-10-05')), 'Monday');
        $this->assertSame(7, WallClock::isoWeekday(WallClock::dayNumber('2026-10-04')), 'Sunday');
        $this->assertSame(4, WallClock::isoWeekday(0), '1 Jan 1970 was a Thursday');
        $this->assertSame(3, WallClock::isoWeekday(WallClock::dayNumber('1969-12-31')), 'before the epoch too');

        $this->assertSame(WallClock::dayNumber('2026-10-05'), WallClock::mondayOf(WallClock::dayNumber('2026-10-08')));
        $this->assertSame(WallClock::dayNumber('2026-10-05'), WallClock::mondayOf(WallClock::dayNumber('2026-10-11')));
        $this->assertSame(WallClock::dayNumber('2026-10-12'), WallClock::mondayOf(WallClock::dayNumber('2026-10-12')));
    }

    #[Test]
    public function local_seconds_of_day_follow_the_zone_offset(): void
    {
        $instant = WallClock::toUtc('2026-03-08', '03:15', 'America/New_York')->getTimestamp();

        $this->assertSame(3 * 3600 + 15 * 60, WallClock::localSecondsOfDay($instant, new DateTimeZone('America/New_York')));
        $this->assertSame(7 * 3600 + 15 * 60, WallClock::localSecondsOfDay($instant, new DateTimeZone('UTC')));
        $this->assertSame('2026-03-08', WallClock::localDate(WallClock::toUtc('2026-03-08', '23:30', 'America/New_York'), 'America/New_York'));
        $this->assertSame('2026-03-09', WallClock::localDate(WallClock::toUtc('2026-03-08', '23:30', 'America/New_York'), 'UTC'));
    }
}
