<?php

namespace App\Domain\Scheduling\Calendar;

use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\Modality;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * What the calendar page was asked to show: the view, the anchor day and the filters.
 * Everything is parsed defensively from the query string (a malformed value is "not set",
 * never an error), so the page always renders.
 */
final readonly class CalendarFilters
{
    public const VIEWS = ['day', 'week', 'month'];

    public function __construct(
        public string $view,
        public CarbonImmutable $date,
        public ?string $clinician = null,
        public ?string $location = null,
        public ?string $service = null,
        public ?AppointmentStatus $status = null,
        public ?Modality $modality = null,
        /** 'all' = only program sessions; a program id = only that program's sessions; null = appointments and program sessions. */
        public ?string $program = null,
    ) {}

    /** @param array<string, mixed> $query  $today: now, in the organization's timezone */
    public static function from(array $query, CarbonImmutable $today): self
    {
        $string = static fn (string $key): ?string => isset($query[$key]) && is_string($query[$key]) && $query[$key] !== '' ? $query[$key] : null;
        $uuid = static fn (string $key): ?string => ($v = $string($key)) !== null && Str::isUuid($v) ? strtolower($v) : null;

        $view = $string('view');
        $date = $string('date');
        $anchor = $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')
            : false;
        // createFromFormat rolls "2025-02-31" over; refuse anything that does not round-trip.
        $anchor = $anchor && $anchor->format('Y-m-d') === $date ? $anchor : CarbonImmutable::createFromFormat('!Y-m-d', $today->format('Y-m-d'), 'UTC');

        return new self(
            view: in_array($view, self::VIEWS, true) ? $view : 'week',
            date: CarbonImmutable::createFromFormat('!Y-m-d', $anchor->format('Y-m-d'), 'UTC'),
            clinician: $uuid('clinician'),
            location: $uuid('location'),
            service: $uuid('service'),
            status: AppointmentStatus::tryFrom((string) $string('status')),
            modality: Modality::tryFrom((string) $string('modality')),
            program: $string('program') === 'all' ? 'all' : $uuid('program'),
        );
    }

    public function isNarrowed(): bool
    {
        return $this->clinician !== null || $this->location !== null || $this->service !== null
            || $this->status !== null || $this->modality !== null || $this->program !== null;
    }

    /** Query parameters that reproduce these filters (empty ones left out). @return array<string, string> */
    public function filterQuery(): array
    {
        return array_filter([
            'clinician' => $this->clinician,
            'location' => $this->location,
            'service' => $this->service,
            'status' => $this->status?->value,
            'modality' => $this->modality?->value,
            'program' => $this->program,
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, string> view + date + filters */
    public function query(?string $view = null, ?string $date = null): array
    {
        return ['view' => $view ?? $this->view, 'date' => $date ?? $this->date->format('Y-m-d')] + $this->filterQuery();
    }
}
