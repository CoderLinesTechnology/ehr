<?php

namespace App\Domain\Scheduling\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Turns a flat list of events into the columns a view draws: one column per day (week), per
 * clinician (day) or per day with a few chips (month). Layout arithmetic lives in EventLayout.
 */
final class CalendarGrid
{
    /** Pixel height of one hour row (comp: 385px for 10 rows) and the least height of a card. */
    public const ROW_HEIGHT = 38.5;

    public const MIN_CARD = 67.0;

    /** Month view shows this many chips per day before "+n more". */
    public const MONTH_CHIPS = 3;

    /**
     * @param  Collection<int, CalendarEvent>  $events
     * @return list<array{date: CarbonImmutable, isToday: bool, placed: list<array{event: CalendarEvent, top: float, height: float, lane: int, lanes: int}>, before: int, after: int}>
     */
    public static function week(Collection $events, CalendarRange $range, int $startHour, int $endHour, string $today): array
    {
        $byDay = $events->groupBy(fn (CalendarEvent $e) => $e->start->format('Y-m-d'));

        return array_map(function (CarbonImmutable $day) use ($byDay, $startHour, $endHour, $today) {
            $date = $day->format('Y-m-d');

            return ['date' => $day, 'isToday' => $date === $today] + self::column($byDay->get($date, collect()), $startHour, $endHour);
        }, $range->days);
    }

    /**
     * Day view: a column per clinician.
     *
     * @param  Collection<int, CalendarEvent>  $events
     * @param  array<string, string>  $clinicians  membership id => display name, in column order
     * @return list<array{clinicianId: string, name: string, placed: list<array{event: CalendarEvent, top: float, height: float, lane: int, lanes: int}>, before: int, after: int}>
     */
    public static function clinicians(Collection $events, array $clinicians, int $startHour, int $endHour): array
    {
        $byClinician = $events->groupBy(fn (CalendarEvent $e) => $e->clinicianId);

        // Someone who has an appointment but is no longer a provider still gets a column: nothing is hidden.
        foreach ($events as $event) {
            $clinicians[$event->clinicianId] ??= $event->clinicianName;
        }

        $columns = [];
        foreach ($clinicians as $id => $name) {
            $columns[] = ['clinicianId' => $id, 'name' => $name] + self::column($byClinician->get($id, collect()), $startHour, $endHour);
        }

        return $columns;
    }

    /**
     * @param  Collection<int, CalendarEvent>  $events
     * @return list<array{date: CarbonImmutable, isToday: bool, inMonth: bool, events: list<CalendarEvent>, more: int}>
     */
    public static function month(Collection $events, CalendarRange $range, CarbonImmutable $anchor, string $today): array
    {
        $byDay = $events->groupBy(fn (CalendarEvent $e) => $e->start->format('Y-m-d'));

        return array_map(function (CarbonImmutable $day) use ($byDay, $anchor, $today) {
            $all = $byDay->get($day->format('Y-m-d'), collect());

            return [
                'date' => $day,
                'isToday' => $day->format('Y-m-d') === $today,
                'inMonth' => $day->month === $anchor->month,
                'events' => $all->take(self::MONTH_CHIPS)->all(),
                'more' => max(0, $all->count() - self::MONTH_CHIPS),
            ];
        }, $range->days);
    }

    /**
     * @param  Collection<int, CalendarEvent>  $events
     * @return array{placed: list<array{event: CalendarEvent, top: float, height: float, lane: int, lanes: int}>, before: int, after: int}
     */
    private static function column(Collection $events, int $startHour, int $endHour): array
    {
        $layout = EventLayout::place(
            $events->map(fn (CalendarEvent $e) => ['key' => $e->id, 'start' => $e->startMinute(), 'end' => $e->endMinute()])->all(),
            $startHour * 60, $endHour * 60, self::ROW_HEIGHT, self::MIN_CARD,
        );

        $placed = [];
        foreach ($events as $event) {
            if (isset($layout['placed'][$event->id])) {
                $placed[] = ['event' => $event] + $layout['placed'][$event->id];
            }
        }

        return ['placed' => $placed, 'before' => $layout['before'], 'after' => $layout['after']];
    }
}
