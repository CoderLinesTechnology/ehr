<?php

namespace App\Domain\Scheduling\Support;

use DateTimeZone;

/**
 * Half-open intervals [start, end) of UTC timestamps (seconds). Pure functions:
 * free time is a window minus busy time; slot starts are aligned to the slot
 * interval in local wall-clock time.
 */
final class Intervals
{
    /**
     * [$start, $end) minus every busy interval, as sorted, disjoint free intervals.
     *
     * @param  list<array{0: int, 1: int}>  $busy  unsorted, may overlap
     * @return list<array{0: int, 1: int}>
     */
    public static function subtract(int $start, int $end, array $busy): array
    {
        usort($busy, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $free = [];
        $cursor = $start;

        foreach ($busy as [$busyStart, $busyEnd]) {
            if ($busyEnd <= $cursor) {
                continue;
            }
            if ($busyStart >= $end) {
                break;
            }
            if ($busyStart > $cursor) {
                $free[] = [$cursor, $busyStart];
            }
            $cursor = $busyEnd;
            if ($cursor >= $end) {
                break;
            }
        }

        if ($cursor < $end) {
            $free[] = [$cursor, $end];
        }

        return $free;
    }

    /**
     * Start times in [$start, $end) whose local wall-clock time in $timezone is a
     * multiple of $interval seconds after midnight, each leaving $duration seconds
     * before $end. Steps are taken on UTC timestamps, so a DST change inside the
     * interval neither invents nor drops a slot.
     *
     * @return list<int>
     */
    public static function alignedStarts(int $start, int $end, int $duration, int $interval, DateTimeZone $timezone, ?int $notAfter = null): array
    {
        $starts = [];
        $candidate = self::alignUp($start, $interval, $timezone);

        // Common case: the zone's UTC offset is constant over the interval (one
        // entry = the state at $start, no transition) and the interval divides
        // the day, so every step stays on the grid without re-checking it.
        $transitions = $timezone->getTransitions($start, max($start, $end));
        if (86400 % $interval === 0 && is_array($transitions) && count($transitions) <= 1) {
            for (; $candidate + $duration <= $end && ($notAfter === null || $candidate <= $notAfter); $candidate += $interval) {
                $starts[] = $candidate;
            }

            return $starts;
        }

        while ($candidate + $duration <= $end && ($notAfter === null || $candidate <= $notAfter)) {
            $starts[] = $candidate;
            $candidate = self::alignUp($candidate + $interval, $interval, $timezone);
        }

        return $starts;
    }

    /** The first aligned instant at or after $timestamp. */
    public static function alignUp(int $timestamp, int $interval, DateTimeZone $timezone): int
    {
        // A UTC-offset change between two steps (DST, or a zone with a 30-minute
        // shift) can leave a candidate misaligned; re-align a bounded number of times.
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $remainder = WallClock::localSecondsOfDay($timestamp, $timezone) % $interval;
            if ($remainder === 0) {
                return $timestamp;
            }
            $timestamp += $interval - $remainder;
        }

        return $timestamp;
    }
}
