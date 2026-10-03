<?php

namespace App\Domain\Scheduling\Calendar;

use Carbon\CarbonImmutable;

/**
 * The span of business dates a view shows, in the organization's timezone, plus the UTC
 * instants that bound it (half-open) for the database.
 */
final readonly class CalendarRange
{
    /** @param list<CarbonImmutable> $days every local date shown, in order */
    private function __construct(
        public array $days,
        public string $timezone,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}

    /** @param int $weekStartsOn 1 = Monday, 7 = Sunday (the organization's setting) */
    public static function for(CalendarFilters $filters, string $timezone, int $weekStartsOn): self
    {
        $anchor = $filters->date;
        $weekStart = static fn (CarbonImmutable $d): CarbonImmutable => $d->startOfWeek($weekStartsOn === 7 ? CarbonImmutable::SUNDAY : CarbonImmutable::MONDAY);

        [$first, $count] = match ($filters->view) {
            'day' => [$anchor, 1],
            'month' => (function () use ($anchor, $weekStart) {
                $first = $weekStart($anchor->startOfMonth());
                $last = $anchor->endOfMonth()->startOfDay();

                return [$first, (int) ceil(((int) $first->diffInDays($last) + 1) / 7) * 7];
            })(),
            default => [$weekStart($anchor), 7],
        };

        $days = [];
        for ($i = 0; $i < $count; $i++) {
            $days[] = $first->addDays($i);
        }

        $local = fn (CarbonImmutable $d) => CarbonImmutable::createFromFormat('!Y-m-d', $d->format('Y-m-d'), $timezone)->utc();

        return new self($days, $timezone, $local($first), $local($first->addDays($count)));
    }

    public function first(): CarbonImmutable
    {
        return $this->days[0];
    }

    public function last(): CarbonImmutable
    {
        return $this->days[count($this->days) - 1];
    }

    /** UTC instants for local midnight of $date and the next midnight (a "today" range). @return array{CarbonImmutable, CarbonImmutable} */
    public static function dayBounds(CarbonImmutable $localDay, string $timezone): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $localDay->format('Y-m-d'), $timezone);

        return [$start->utc(), $start->addDay()->utc()];
    }
}
