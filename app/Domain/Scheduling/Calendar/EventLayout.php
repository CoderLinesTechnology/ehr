<?php

namespace App\Domain\Scheduling\Calendar;

/**
 * Places the events of ONE day column on a vertical hour grid and puts overlapping ones side by
 * side. Pure arithmetic, so it is tested without a database.
 *
 * Positions are in pixels from the top of the body. A card is never shorter than $minHeight (its
 * four text lines need that), so overlap is decided on the DRAWN extent, not on the appointment's
 * duration: two consecutive one-hour appointments would otherwise be painted on top of each other.
 */
final class EventLayout
{
    /**
     * @param  list<array{key: string, start: int, end: int}>  $events  minutes since local midnight
     * @return array{placed: array<string, array{top: float, height: float, lane: int, lanes: int}>, before: int, after: int}
     *     `before` / `after` count events that fall wholly outside the visible window
     */
    public static function place(array $events, int $windowStartMin, int $windowEndMin, float $rowHeight, float $minHeight): array
    {
        $perMinute = $rowHeight / 60;
        $bodyHeight = ($windowEndMin - $windowStartMin) * $perMinute;
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
            $top = max(0.0, ($event['start'] - $windowStartMin) * $perMinute);
            $bottom = min($bodyHeight, max($top + $minHeight, ($event['end'] - $windowStartMin) * $perMinute));
            $top = max(0.0, min($top, $bottom - min($minHeight, $bodyHeight)));
            $items[] = ['key' => $event['key'], 'top' => $top, 'bottom' => $bottom];
        }

        usort($items, fn ($a, $b) => [$a['top'], $a['bottom']] <=> [$b['top'], $b['bottom']]);

        $placed = [];
        $cluster = [];
        $clusterEnd = -1.0;
        $flush = function () use (&$cluster, &$placed) {
            $lanes = $cluster === [] ? 0 : max(array_column($cluster, 'lane')) + 1;
            foreach ($cluster as $item) {
                $placed[$item['key']] = ['top' => round($item['top'], 2), 'height' => round($item['bottom'] - $item['top'], 2), 'lane' => $item['lane'], 'lanes' => $lanes];
            }
            $cluster = [];
        };

        $laneEnds = [];
        foreach ($items as $item) {
            if ($item['top'] >= $clusterEnd) {
                $flush();
                $laneEnds = [];
                $clusterEnd = -1.0;
            }
            $lane = 0;
            while (isset($laneEnds[$lane]) && $laneEnds[$lane] > $item['top']) {
                $lane++;
            }
            $laneEnds[$lane] = $item['bottom'];
            $clusterEnd = max($clusterEnd, $item['bottom']);
            $cluster[] = $item + ['lane' => $lane];
        }
        $flush();

        return ['placed' => $placed, 'before' => $before, 'after' => $after];
    }
}
