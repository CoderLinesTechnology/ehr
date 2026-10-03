<?php

namespace App\Domain\Scheduling\Calendar;

use App\Domain\Identity\PermissionResolver;
use App\Domain\Programs\ProgramCalendar;
use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Appointment;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read side of the calendar: bounded, visibility-scoped, eager-loaded.
 *
 *   appointments()  one query for the range + one per relation (5), never per row
 *   today()         the same shape for one day, capped
 *   markedDates()   ONE aggregate query for the mini calendar's dots
 *
 * Visibility is the AppointmentPolicy rule applied to the query: `appointments.view_all` sees
 * everything, `appointments.view` only appointments the member is the clinician on. The tenant
 * comes from TenantContext (the Appointment model's scope), never from a parameter.
 */
final class CalendarQuery
{
    /** Hard cap on one range (a month grid of a very busy organization). */
    public const MAX_EVENTS = 800;

    public const TODAY_LIMIT = 50;

    /** Shown unless the Status filter asks for something else. */
    private const HIDDEN_BY_DEFAULT = ['cancelled', 'rescheduled'];

    private const COLUMNS = ['id', 'organization_id', 'client_id', 'service_id', 'clinician_membership_id', 'location_id', 'starts_at', 'ends_at', 'timezone', 'modality', 'status'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PermissionResolver $permissions,
        private readonly SettingsService $settings,
        private readonly ProgramCalendar $programs,
    ) {}

    public function membership(): OrganizationMembership
    {
        return $this->tenant->membership() ?? abort(404);
    }

    public function seesAll(): bool
    {
        return $this->permissions->membershipHas($this->membership(), 'appointments.view_all');
    }

    public function timezone(): string
    {
        return $this->tenant->organizationOrFail()->timezone;
    }

    public function weekStartsOn(): int
    {
        return (int) $this->settings->organization($this->tenant->organizationOrFail(), 'general.week_starts_on') === 7 ? 7 : 1;
    }

    /** @return array{0: int, 1: int} the grid's first and last (exclusive) hour, from the organization's settings */
    public function dayHours(): array
    {
        $organization = $this->tenant->organizationOrFail();
        $hour = function (string $key, int $default, bool $ceil) use ($organization): int {
            $value = (string) $this->settings->organization($organization, $key);
            if (preg_match('/^(\d{1,2}):(\d{2})/', $value, $m) !== 1) {
                return $default;
            }

            return min(24, (int) $m[1] + ($ceil && (int) $m[2] > 0 ? 1 : 0));
        };
        $start = $hour('scheduling.calendar_day_start', 8, false);
        $end = $hour('scheduling.calendar_day_end', 18, true);

        return $end > $start ? [$start, $end] : [8, 18];
    }

    /** @return Collection<int, CalendarEvent> */
    public function appointments(CalendarFilters $filters, CalendarRange $range): Collection
    {
        // A program filter asks for program sessions only; otherwise they are drawn beside the appointments (read-only).
        $rows = $filters->program !== null ? new Collection : $this->base($filters)
            ->where('starts_at', '>=', $range->startsAt)
            ->where('starts_at', '<', $range->endsAt)
            ->orderBy('starts_at')->orderBy('id')
            ->limit(self::MAX_EVENTS)
            ->get();

        return $this->present($rows)->concat($this->programs->events($filters, $range, $this->timezone()))
            ->sortBy(fn (CalendarEvent $e) => [$e->start->getTimestamp(), $e->id])->values();
    }

    /** @return Collection<int, CalendarEvent> today's appointments (organization's local day of $now) */
    public function today(CalendarFilters $filters, CarbonImmutable $now): Collection
    {
        [$from, $to] = CalendarRange::dayBounds($now->setTimezone($this->timezone()), $this->timezone());

        $rows = $this->base($filters)
            ->where('starts_at', '>=', $from)->where('starts_at', '<', $to)
            ->orderBy('starts_at')->orderBy('id')
            ->limit(self::TODAY_LIMIT)
            ->get();

        return $this->present($rows);
    }

    /**
     * Local date => membership id of the first clinician that day, for every day in [$from, $to).
     * One grouped query (visibility applied, filters ignored: the dots say "something is booked").
     *
     * @return array<string, string>
     */
    public function markedDates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = $this->timezone();
        $query = $this->visible(Appointment::query())
            ->whereIn('status', AppointmentStatus::OCCUPYING)
            ->where('starts_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $from->format('Y-m-d'), $tz)->utc())
            ->where('starts_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $to->format('Y-m-d'), $tz)->utc())
            ->selectRaw("to_char(starts_at at time zone ?, 'YYYY-MM-DD') as day, (array_agg(clinician_membership_id::text order by starts_at))[1] as clinician", [$tz])
            ->groupByRaw('1');

        return $query->get()->pluck('clinician', 'day')->all();
    }

    /** @return array{clinicians: array<string, string>, colors: array<string, string|null>, locations: array<string, string>, services: array<string, string>} */
    public function options(): array
    {
        $providers = OrganizationMembership::query()->providers()
            ->select(['id', 'organization_id', 'user_id', 'name_prefix', 'color'])
            ->with('user:id,name')->limit(100)->get();
        if (! $this->seesAll()) {
            $providers = $providers->where('id', $this->membership()->id);
        }

        return [
            'clinicians' => $providers->sortBy(fn ($m) => mb_strtolower($m->displayName()))->mapWithKeys(fn ($m) => [$m->id => $m->professionalName()])->all(),
            'colors' => $providers->mapWithKeys(fn ($m) => [$m->id => $m->color])->all(),
            'locations' => Location::query()->active()->orderBy('sort')->orderBy('name')->limit(100)->pluck('name', 'id')->all(),
            'services' => Service::query()->active()->orderBy('sort')->orderBy('name')->limit(200)->pluck('name', 'id')->all(),
        ];
    }

    /** @return Builder<Appointment> */
    private function base(CalendarFilters $filters): Builder
    {
        return $this->visible(Appointment::query())
            ->select(self::COLUMNS)
            ->with([
                'client:id,organization_id,first_name,last_name,preferred_name',
                'service:id,organization_id,name',
                'clinician:id,organization_id,user_id,name_prefix,color',
                'clinician.user:id,name',
                'location:id,organization_id,name',
            ])
            ->when($filters->clinician, fn ($q, $id) => $q->where('clinician_membership_id', $id))
            ->when($filters->location, fn ($q, $id) => $q->where('location_id', $id))
            ->when($filters->service, fn ($q, $id) => $q->where('service_id', $id))
            ->when($filters->modality, fn ($q, $m) => $q->where('modality', $m->value))
            ->when(
                $filters->status,
                fn ($q, $s) => $q->where('status', $s->value),
                fn ($q) => $q->whereNotIn('status', self::HIDDEN_BY_DEFAULT),
            );
    }

    /** @param Builder<Appointment> $query @return Builder<Appointment> */
    private function visible(Builder $query): Builder
    {
        $membership = $this->membership();

        if (! $membership->isActive()) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->seesAll()) {
            return $query;
        }

        return $this->permissions->membershipHas($membership, 'appointments.view')
            ? $query->where('clinician_membership_id', $membership->id)
            : $query->whereRaw('1 = 0');
    }

    /** @param Collection<int, Appointment> $rows @return Collection<int, CalendarEvent> */
    private function present(Collection $rows): Collection
    {
        $tz = $this->timezone();
        $colors = $rows->mapWithKeys(fn ($a) => [$a->clinician_membership_id => $a->clinician->color])->all();

        return $rows->map(fn (Appointment $a) => CalendarEvent::from($a, $tz, $colors))->values();
    }
}
