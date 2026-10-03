<?php

namespace App\Domain\Scheduling;

use App\Domain\Identity\MembershipStatus;
use App\Domain\Scheduling\Support\AvailabilityWindow;
use App\Domain\Scheduling\Support\BookingRules;
use App\Domain\Scheduling\Support\Conflicts;
use App\Domain\Scheduling\Support\Intervals;
use App\Domain\Scheduling\Support\SchedulingSettings;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Scheduling\Support\WallClock;
use App\Domain\Tenancy\TenantContext;
use App\Models\AvailabilityRule;
use App\Models\Service;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The only code that answers "when can this service be booked?". Staff booking,
 * online booking, the portal and waitlist matching all ask it.
 *
 * Free time = availability rules expanded over the range (in each rule's own
 * timezone, DST-correct) − blocked time − the clinicians' occupying
 * appointments. Starts are aligned to the organization's slot interval in
 * local time, and each slot must fit the service's duration.
 *
 * Whatever the range, clinicians or rules: one query for the rules (with the
 * provider and service-restriction checks folded in), one for blocked time, one
 * for appointments, plus a lookup of the service's locations and the
 * (request-memoised) organization settings.
 */
final class SlotFinder
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SchedulingSettings $settings,
        private readonly BookingRules $bookingRules,
    ) {}

    public function find(SlotQuery $query): SlotResult
    {
        $organization = $this->tenant->organizationOrFail();
        TenantGuard::assertOwned($organization->id, $query->service, $query->clinician, $query->location);

        $modalities = $this->modalities($query);
        if ($modalities === [] || ! $this->worthSearching($query)) {
            return SlotResult::empty();
        }

        $duration = $query->service->duration_minutes * 60;
        $interval = $this->settings->slotIntervalMinutes($organization) * 60;
        [$earliest, $latest] = [null, null];
        if ($query->forOnlineBooking) {
            $now = now()->utc();
            $earliest = $now->addHours($this->settings->minNoticeHours($organization))->getTimestamp();
            $latest = $now->addDays($this->settings->maxAdvanceDays($organization))->getTimestamp();
        }

        $rules = $this->rules($query, $modalities);
        if ($rules->isEmpty()) {
            return SlotResult::empty();
        }

        $allowedLocationIds = in_array(Modality::InPerson, $modalities, true) ? $this->serviceLocationIds($query->service) : null;
        $windows = $this->windows($rules, $query, $modalities, $allowedLocationIds, $organization->timezone, $earliest, $latest, $duration);
        if ($windows === []) {
            return SlotResult::empty();
        }

        return $this->slots($windows, $duration, $interval, $latest);
    }

    /** @return list<Modality> ways of meeting this query can produce */
    private function modalities(SlotQuery $query): array
    {
        return array_values(array_filter(
            $query->service->modalities(),
            fn (Modality $modality) => ($query->modality === null || $query->modality === $modality)
                && ($query->location === null || $modality === Modality::InPerson),
        ));
    }

    private function worthSearching(SlotQuery $query): bool
    {
        $service = $query->service;

        return $service->is_active
            && (! $query->forOnlineBooking || $service->is_bookable_online)
            && ($query->clinician === null || $this->bookingRules->isBookableClinician($query->clinician))
            && ($query->location === null || $query->location->is_active);
    }

    /**
     * Active rules of active providers of the service, effective in the range,
     * open to this service, at an active location (or none), that can produce
     * one of the wanted modalities.
     *
     * @param  list<Modality>  $modalities
     * @return Collection<int, AvailabilityRule>
     */
    private function rules(SlotQuery $query, array $modalities): Collection
    {
        $service = $query->service;
        $ruleModalities = [AvailabilityModality::Any->value, ...array_map(fn (Modality $m) => $m->value, $modalities)];

        return AvailabilityRule::query()
            ->select([
                'availability_rules.id', 'availability_rules.membership_id', 'availability_rules.location_id',
                'availability_rules.weekday', 'availability_rules.start_time', 'availability_rules.end_time',
                'availability_rules.modality', 'availability_rules.repeat_every_weeks',
                'availability_rules.effective_from', 'availability_rules.effective_until',
                'locations.timezone as location_timezone',
            ])
            ->leftJoin('locations', function (JoinClause $join) {
                $join->on('locations.organization_id', '=', 'availability_rules.organization_id')
                    ->on('locations.id', '=', 'availability_rules.location_id');
            })
            ->where('availability_rules.is_active', true)
            ->whereIn('availability_rules.modality', $ruleModalities)
            ->where('availability_rules.effective_from', '<=', $query->to)
            ->where(fn (Builder $q) => $q->whereNull('availability_rules.effective_until')
                ->orWhere('availability_rules.effective_until', '>=', $query->from))
            ->where(fn (Builder $q) => $q->whereNull('availability_rules.location_id')
                ->orWhere('locations.is_active', true))
            ->whereIn('availability_rules.membership_id', fn (QueryBuilder $providers) => $providers
                ->select('service_providers.membership_id')
                ->from('service_providers')
                ->join('organization_memberships', function (JoinClause $join) {
                    $join->on('organization_memberships.organization_id', '=', 'service_providers.organization_id')
                        ->on('organization_memberships.id', '=', 'service_providers.membership_id');
                })
                ->where('service_providers.organization_id', $service->organization_id)
                ->where('service_providers.service_id', $service->id)
                ->where('organization_memberships.status', MembershipStatus::Active->value)
                ->where('organization_memberships.is_provider', true))
            ->where(fn (Builder $q) => $q
                ->whereNotExists(fn (QueryBuilder $restriction) => $restriction
                    ->select(DB::raw(1))
                    ->from('availability_rule_services')
                    ->whereColumn('availability_rule_services.availability_rule_id', 'availability_rules.id'))
                ->orWhereExists(fn (QueryBuilder $restriction) => $restriction
                    ->select(DB::raw(1))
                    ->from('availability_rule_services')
                    ->whereColumn('availability_rule_services.availability_rule_id', 'availability_rules.id')
                    ->where('availability_rule_services.service_id', $service->id)))
            ->when($query->clinician, fn (Builder $q, $clinician) => $q->where('availability_rules.membership_id', $clinician->id))
            ->when($query->location, fn (Builder $q, $location) => $q->where('availability_rules.location_id', $location->id))
            ->when($query->forOnlineBooking, fn (Builder $q) => $q->where('availability_rules.is_bookable_online', true))
            ->get();
    }

    /** @return list<string>|null the service's locations; null = offered at every active location */
    private function serviceLocationIds(Service $service): ?array
    {
        $ids = DB::table('service_locations')
            ->where('organization_id', $service->organization_id)
            ->where('service_id', $service->id)
            ->pluck('location_id')
            ->all();

        return $ids === [] ? null : $ids;
    }

    /**
     * Each rule's occurrences in the range, one window per way of meeting.
     *
     * @param  Collection<int, AvailabilityRule>  $rules
     * @param  list<Modality>  $modalities
     * @param  list<string>|null  $allowedLocationIds
     * @return list<AvailabilityWindow>
     */
    private function windows(
        Collection $rules,
        SlotQuery $query,
        array $modalities,
        ?array $allowedLocationIds,
        string $organizationTimezone,
        ?int $earliest,
        ?int $latest,
        int $duration,
    ): array {
        $firstDay = WallClock::dayNumber($query->from);
        $lastDay = WallClock::dayNumber($query->to);
        $zones = [];
        $windows = [];

        foreach ($rules as $rule) {
            $ruleTimezone = $rule->location_id !== null ? (string) $rule->location_timezone : $organizationTimezone;
            $variants = $this->variants($rule, $modalities, $allowedLocationIds, $ruleTimezone, $organizationTimezone);
            if ($variants === []) {
                continue;
            }

            $zone = $zones[$ruleTimezone] ??= new DateTimeZone($ruleTimezone);
            $effectiveFrom = WallClock::dayNumber($rule->effective_from);
            $from = max($firstDay, $effectiveFrom);
            $to = $rule->effective_until !== null ? min($lastDay, WallClock::dayNumber($rule->effective_until)) : $lastDay;
            // repeat_every_weeks counts ISO weeks from the week effective_from falls in.
            $anchorMonday = WallClock::mondayOf($effectiveFrom);
            $repeat = max(1, $rule->repeat_every_weeks);

            for ($day = $from + (($rule->weekday - WallClock::isoWeekday($from)) + 7) % 7; $day <= $to; $day += 7) {
                if (intdiv(WallClock::mondayOf($day) - $anchorMonday, 7) % $repeat !== 0) {
                    continue;
                }

                $date = WallClock::dateOf($day);
                $start = WallClock::timestamp($date, $rule->start_time, $zone);
                $end = WallClock::timestamp($date, $rule->end_time, $zone);

                if ($earliest !== null) {
                    $start = max($start, $earliest);
                }
                if ($end - $start < $duration || ($latest !== null && $start > $latest)) {
                    continue;
                }

                foreach ($variants as [$modality, $locationId, $displayTimezone]) {
                    $windows[] = new AvailabilityWindow($rule->membership_id, $locationId, $modality, $ruleTimezone, $displayTimezone, $start, $end);
                }
            }
        }

        return $windows;
    }

    /**
     * The (modality, location, display timezone) combinations a rule offers:
     * in person at its location if the service is offered there; telehealth
     * with no location, shown in the organization's timezone.
     *
     * @param  list<Modality>  $modalities
     * @param  list<string>|null  $allowedLocationIds
     * @return list<array{0: Modality, 1: ?string, 2: string}>
     */
    private function variants(AvailabilityRule $rule, array $modalities, ?array $allowedLocationIds, string $ruleTimezone, string $organizationTimezone): array
    {
        $variants = [];

        foreach ($modalities as $modality) {
            if (! $rule->modality->permits($modality)) {
                continue;
            }

            if ($modality === Modality::Telehealth) {
                $variants[] = [Modality::Telehealth, null, $organizationTimezone];

                continue;
            }

            if ($rule->location_id !== null && ($allowedLocationIds === null || in_array($rule->location_id, $allowedLocationIds, true))) {
                $variants[] = [Modality::InPerson, $rule->location_id, $ruleTimezone];
            }
        }

        return $variants;
    }

    /** @param list<AvailabilityWindow> $windows */
    private function slots(array $windows, int $duration, int $interval, ?int $latest): SlotResult
    {
        $from = WallClock::instant(min(array_map(fn (AvailabilityWindow $w) => $w->start, $windows)));
        $to = WallClock::instant(max(array_map(fn (AvailabilityWindow $w) => $w->end, $windows)));
        $clinicianIds = array_values(array_unique(array_map(fn (AvailabilityWindow $w) => $w->clinicianId, $windows)));

        $epochs = 'floor(extract(epoch from starts_at))::bigint AS start_ts, ceil(extract(epoch from ends_at))::bigint AS end_ts';

        $appointments = [];
        foreach (Conflicts::appointments($clinicianIds, $from, $to)->toBase()->select('clinician_membership_id')->selectRaw($epochs)->get() as $row) {
            $appointments[$row->clinician_membership_id][] = [(int) $row->start_ts, (int) $row->end_ts];
        }

        $blocks = [];
        foreach (Conflicts::blocks($clinicianIds, $from, $to)->toBase()->select(['membership_id', 'location_id'])->selectRaw($epochs)->get() as $row) {
            $blocks[] = [$row->membership_id, $row->location_id, (int) $row->start_ts, (int) $row->end_ts];
        }

        $zones = [];
        $slots = [];
        foreach ($windows as $window) {
            // Only what overlaps this window, so the sort inside subtract() stays tiny.
            $busy = [];
            foreach ($appointments[$window->clinicianId] ?? [] as $appointment) {
                if ($appointment[1] > $window->start && $appointment[0] < $window->end) {
                    $busy[] = $appointment;
                }
            }
            foreach ($blocks as [$blockMembershipId, $blockLocationId, $blockStart, $blockEnd]) {
                if ($blockEnd > $window->start && $blockStart < $window->end
                    && Conflicts::blockApplies($blockMembershipId, $blockLocationId, $window->clinicianId, $window->locationId)) {
                    $busy[] = [$blockStart, $blockEnd];
                }
            }

            $zone = $zones[$window->ruleTimezone] ??= new DateTimeZone($window->ruleTimezone);
            foreach (Intervals::subtract($window->start, $window->end, $busy) as [$freeStart, $freeEnd]) {
                foreach (Intervals::alignedStarts($freeStart, $freeEnd, $duration, $interval, $zone, $latest) as $start) {
                    // Fixed-width start first: sorting the keys sorts by start, clinician, modality, location.
                    $key = sprintf('%012d', $start).'|'.$window->clinicianId.'|'.$window->modality->value.'|'.($window->locationId ?? '');
                    $slots[$key] ??= new Slot(
                        WallClock::instant($start),
                        WallClock::instant($start + $duration),
                        $window->clinicianId,
                        $window->locationId,
                        $window->modality,
                        $window->displayTimezone,
                    );
                }
            }
        }

        ksort($slots, SORT_STRING);

        return new SlotResult(array_values($slots));
    }
}
