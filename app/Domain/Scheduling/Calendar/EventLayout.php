<?php

namespace App\Domain\Scheduling\Calendar;

/**
 * Places the events of ONE day column on a vertical hour grid and puts overlapping ones side by
 * side. Pure arithmetic, so it is tested without a database.
 *
 * Positions are in pixels from the top of the body. Side-by-side lanes mean a REAL time overlap
 * (in a scheduling tool, two cards next to each other read as a double-booking). A card is drawn
 * at least $minHeight tall so its text lines fit, but never past the start of the next card in its
 * lane: back-to-back appointments stack, the earlier card clipped, rather than being squeezed
 * into columns as if they clashed.
 */
final class EventLayout
{
    /** Vertical gap kept between a card and the next one in its lane. */
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
            // A card near the bottom edge is pulled up so it stays whole.
            $top = max(0.0, min(($start - $windowStartMin) * $perMinute, $bodyHeight - min($minHeight, $bodyHeight)));
            $items[] = [
                'key' => $event['key'],
                'start' => $start,
                'end' => $end,
                'top' => $top,
                // Drawn extent before lane capping: at least the minimum height, inside the body.
                'bottom' => min($bodyHeight, max($top + $minHeight, ($end - $windowStartMin) * $perMinute)),
            ];
        }

        usort($items, fn ($a, $b) => [$a['start'], $a['end'], $a['key']] <=> [$b['start'], $b['end'], $b['key']]);

        // Lanes and clusters by real time overlap.
        $clusters = [];
        $cluster = [];
        $clusterEnd = PHP_INT_MIN;
        $laneEnds = [];
        foreach ($items as $item) {
            if ($item['start'] >= $clusterEnd && $cluster !== []) {
                $clusters[] = $cluster;
                $cluster = [];
                $laneEnds = [];
            }
            $lane = 0;
            while (isset($laneEnds[$lane]) && $laneEnds[$lane] > $item['start']) {
                $lane++;
            }
            $laneEnds[$lane] = $item['end'];
            $clusterEnd = $cluster === [] ? $item['end'] : max($clusterEnd, $item['end']);
            $cluster[] = $item + ['lane' => $lane];
        }
        if ($cluster !== []) {
            $clusters[] = $cluster;
        }

        // A card never runs into the next card that will be drawn in the same horizontal space.
        $placed = [];
        $all = array_merge(...($clusters ?: [[]]));
        foreach ($clusters as $members) {
            $lanes = max(array_column($members, 'lane')) + 1;
            foreach ($members as $item) {
                $bottom = $item['bottom'];
                foreach ($all as $other) {
                    if ($other['key'] === $item['key'] || $other['top'] <= $item['top']) {
                        continue;
                    }
                    $sharesSpace = $other['lane'] === $item['lane'] || ! self::sameCluster($clusters, $item['key'], $other['key']);
                    if ($sharesSpace && $other['top'] < $bottom + self::GAP) {
                        $bottom = max($item['top'] + 1, $other['top'] - self::GAP);
                    }
                }
                $placed[$item['key']] = [
                    'top' => round($item['top'], 2),
                    'height' => round($bottom - $item['top'], 2),
                    'lane' => $item['lane'],
                    'lanes' => $lanes,
                ];
            }
        }

        return ['placed' => $placed, 'before' => $before, 'after' => $after];
    }

    /** @param list<list<array{key: string}>> $clusters */
    private static function sameCluster(array $clusters, string $a, string $b): bool
    {
        foreach ($clusters as $members) {
            $keys = array_column($members, 'key');
            if (in_array($a, $keys, true)) {
                return in_array($b, $keys, true);
            }
        }

        return false;
    }
}
