<?php

namespace App\Domain\Scheduling\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Business dates and wall-clock times → UTC instants, DST-correct.
 *
 * Local times are always built from their components in the target timezone
 * (never by adding hours to another local time), so a window that crosses a
 * DST change covers the real elapsed time. Arithmetic on instants happens on
 * UTC timestamps only. Calendar arithmetic uses day numbers (days since
 * 1970-01-01), which have no timezone at all.
 */
final class WallClock
{
    private const DATE = '/^(\d{4})-(\d{2})-(\d{2})$/';

    private const TIME = '/^(\d{2}):(\d{2})(?::(\d{2}))?$/';

    /**
     * The instant at which it is $time on $date in $timezone. A time inside a
     * spring-forward gap moves forward by the gap; an ambiguous fall-back time
     * resolves to its first occurrence; '24:00' is the next local midnight.
     */
    public static function toUtc(string $date, string $time, string $timezone): CarbonImmutable
    {
        [$year, $month, $day] = self::dateParts($date);
        [$hour, $minute, $second] = self::timeParts($time);

        return CarbonImmutable::create($year, $month, $day, $hour, $minute, $second, $timezone)->utc();
    }

    /**
     * toUtc() as a Unix timestamp, for hot loops: the same construction from
     * components in the zone (what CarbonImmutable::create wraps), without
     * Carbon's overhead (~20× cheaper). The two are asserted equal in tests.
     */
    public static function timestamp(string $date, string $time, DateTimeZone $timezone): int
    {
        [$year, $month, $day] = self::dateParts($date);
        [$hour, $minute, $second] = self::timeParts($time);

        return (new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second), $timezone))
            ->getTimestamp();
    }

    /** A UTC CarbonImmutable for a Unix timestamp (createFromInterface is the cheap constructor). */
    public static function instant(int $timestamp): CarbonImmutable
    {
        static $utc = new DateTimeZone('UTC');

        return CarbonImmutable::createFromInterface((new DateTimeImmutable('@'.$timestamp))->setTimezone($utc));
    }

    public static function isDate(string $value): bool
    {
        if (! preg_match(self::DATE, $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** 'HH:MM' or 'HH:MM:SS', 00:00–24:00. */
    public static function isTime(string $value): bool
    {
        if (! preg_match(self::TIME, $value, $m)) {
            return false;
        }

        [$hour, $minute, $second] = [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];

        return ($hour < 24 && $minute < 60 && $second < 60) || ($hour === 24 && $minute === 0 && $second === 0);
    }

    /** Seconds since local midnight, for comparing wall-clock times. */
    public static function secondsOfDay(string $time): int
    {
        [$hour, $minute, $second] = self::timeParts($time);

        return $hour * 3600 + $minute * 60 + $second;
    }

    /** Days since 1970-01-01 for a 'Y-m-d' business date. */
    public static function dayNumber(string $date): int
    {
        [$year, $month, $day] = self::dateParts($date);

        return intdiv((new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), new DateTimeZone('UTC')))->getTimestamp(), 86400);
    }

    public static function dateOf(int $dayNumber): string
    {
        return gmdate('Y-m-d', $dayNumber * 86400);
    }

    /** ISO weekday (1 = Monday … 7 = Sunday) of a day number. */
    public static function isoWeekday(int $dayNumber): int
    {
        // Day 0 (1970-01-01) was a Thursday.
        return ((($dayNumber + 3) % 7) + 7) % 7 + 1;
    }

    /** Day number of the Monday of the ISO week containing $dayNumber. */
    public static function mondayOf(int $dayNumber): int
    {
        return $dayNumber - (self::isoWeekday($dayNumber) - 1);
    }

    /** The local business date of an instant in $timezone. */
    public static function localDate(\DateTimeInterface $instant, string $timezone): string
    {
        return CarbonImmutable::instance($instant)->setTimezone($timezone)->format('Y-m-d');
    }

    /** Seconds since local midnight of a UTC timestamp, in $timezone. */
    public static function localSecondsOfDay(int $timestamp, DateTimeZone $timezone): int
    {
        $local = $timestamp + $timezone->getOffset(new DateTimeImmutable('@'.$timestamp));

        return (($local % 86400) + 86400) % 86400;
    }

    /** @return array{int, int, int} */
    private static function dateParts(string $date): array
    {
        if (! self::isDate($date)) {
            throw new InvalidArgumentException("Not a Y-m-d date: [{$date}].");
        }

        return array_map('intval', explode('-', $date));
    }

    /** @return array{int, int, int} */
    private static function timeParts(string $time): array
    {
        if (! self::isTime($time)) {
            throw new InvalidArgumentException("Not a wall-clock time: [{$time}].");
        }

        $parts = array_map('intval', explode(':', $time));

        return [$parts[0], $parts[1], $parts[2] ?? 0];
    }
}
