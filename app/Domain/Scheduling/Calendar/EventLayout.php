<?php

namespace App\Domain\Scheduling\Calendar;

/**
 * Places the events of ONE day column on a vertical hour grid and puts overlapping ones side by
 * side. Pure arithmetic, so it is tested without a database.
 *
 * Positions are in pixels from the top of the body.
 *  - Lanes (side by side) only for a REAL time overlap — in a scheduling tool, two cards next to
 *    each other read as a double-booking.
 *  - A card is drawn at least $minHeight tall so its text fits, but never into the next card that
 *    shares its horizontal space: back-to-back appointments stack, the earlier one clipped.
 *  - A card near the bottom edge is pulled up to stay whole, but never above the end of the
 *    previous appointment in its lane.
 */
final class EventLayout
{
    /** Vertical gap kept between a card and the next one that shares its space. */
    private const GAP = 2.0;

    /**
     * @param  list<array{key: string, start: int, end: int}>  $events  minutes since local midnight
     * @return array{placed: array<string, array{top: float, height: float, lane: int, lanes: int}>, before: int, after: int}
     *     `before` / `after` count events that fall wholly outside the visible window
     */
    public static function place(array $events, int $windowStartMin, int $windowEndMin, float $rowHeight, float $minHeight): array
    {
        $perMinute = $rowHeight / 60;
        $bodyHeight = ($windowEndMin - $windowStartMin) * $perMinute;
        $minHeight = min($minHeight, $bodyHeight);
        $items = [];
        $before = $after = 0;

        foreach ($events as $event) {
            if ($event['end'] <= $windowStartMin) {
                $before++;

                continue;
            }
            if ($event['start'] >= $windowEndMin) {
                $after++;

                continue;
            }
            $start = max($event['start'], $windowStartMin);
            $end = min(max($event['end'], $start + 1), $windowEndMin);
            $items[] = [
                'key' => $event['key'],
                'start' => $start,
                'end' => $end,
                'timeTop' => ($start - $windowStartMin) * $perMinute,
                'timeBottom' => ($end - $windowStartMin) * $perMinute,
            ];
        }

        usort($items, fn ($a, $b) => [$a['start'], $a['end'], $a['key']] <=> [$b['start'], $b['end'], $b['key']]);

        // 1. Lanes and clusters by real time overlap.
        $cluster = 0;
        $clusterEnd = PHP_INT_MIN;
        $laneEnds = [];
        $lanesInCluster = [];
        foreach ($items as $i => $item) {
            if ($item['start'] >= $clusterEnd) {
                $cluster++;
                $laneEnds = [];
            }
            $lane = 0;
            while (isset($laneEnds[$lane]) && $laneEnds[$lane] > $item['start']) {
                $lane++;
            }
            $laneEnds[$lane] = $item['end'];
            $clusterEnd = max($clusterEnd === PHP_INT_MIN ? $item['end'] : $clusterEnd, $item['end']);
            $items[$i]['lane'] = $lane;
            $items[$i]['cluster'] = $cluster;
            $lanesInCluster[$cluster] = max($lanesInCluster[$cluster] ?? 0, $lane + 1);
        }

        // Cards b after a share space when a full-width card meets anything, or within a cluster on the same lane.
        $shares = fn (array $a, array $b): bool => $a['cluster'] !== $b['cluster'] || $a['lane'] === $b['lane'];

        // 2. Tops: real start time, pulled up near the bottom edge but never above the
        //    previous card's real end in the same space.
        foreach ($items as $i => $item) {
            $top = $item['timeTop'];
            if ($top + $minHeight > $bodyHeight) {
                $floor = 0.0;
                for ($j = $i - 1; $j >= 0; $j--) {
                    if ($shares($items[$j], $item)) {
                        $floor = $items[$j]['timeBottom'] + self::GAP;
                        break;
                    }
                }
                $top = max($floor, min($top, $bodyHeight - $minHeight));
            }
            $items[$i]['top'] = $top;
        }

        // 3. Heights: at least the minimum, never into the next card sharing the space.
        $placed = [];
        foreach ($items as $i => $item) {
            $bottom = min($bodyHeight, max($item['top'] + $minHeight, $item['timeBottom']));
            for ($j = $i + 1, $n = count($items); $j < $n; $j++) {
                if ($shares($item, $items[$j]) && $items[$j]['top'] < $bottom + self::GAP) {
                    $bottom = max($item['top'] + 1, $items[$j]['top'] - self::GAP);
                    break;
                }
            }
            $placed[$item['key']] = [
                'top' => round($item['top'], 2),
                'height' => round($bottom - $item['top'], 2),
                'lane' => $item['lane'],
                'lanes' => $lanesInCluster[$item['cluster']],
            ];
        }

        return ['placed' => $placed, 'before' => $before, 'after' => $after];
    }
}
