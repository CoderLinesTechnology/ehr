<?php

namespace App\Domain\Programs;

use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\Calendar\CalendarEvent;
use App\Domain\Scheduling\Calendar\CalendarFilters;
use App\Domain\Scheduling\Calendar\CalendarRange;
use App\Domain\Scheduling\Modality;
use App\Domain\Tenancy\TenantContext;
use App\Models\ProgramSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Program group sessions as calendar events (read-only there). The central calendar calls this and merges the result
 * with the appointments, so one grid shows both.
 *
 * Who sees them: members with `programs.view` of an organization entitled to `programs` and `groups`. A session is
 * a time and a place, not participants: sessions of a 42 CFR Part 2 program are shown like any other (the card carries
 * no client data); participants and attendance stay behind `programs.view_sud` on the program screens.
 *
 * The calendar's filters apply where they make sense: clinician = facilitator, location, modality (online =
 * telehealth), status (a session is "confirmed"); a service filter has no sessions.
 */
final class ProgramCalendar
{
    /** Cap on one range, like the appointments'. */
    public const MAX_EVENTS = 200;

    /** Programs draw in their own colour family; the calendar has no red, so red reads orange. */
    private const FAMILY = ['blue' => 'blue', 'green' => 'green', 'purple' => 'purple', 'orange' => 'orange', 'red' => 'orange', 'teal' => 'teal'];

    public function __construct(private readonly TenantContext $tenant, private readonly EntitlementService $entitlements) {}

    /** @return Collection<int, CalendarEvent> */
    public function events(CalendarFilters $filters, CalendarRange $range, string $timezone): Collection
    {
        $membership = $this->tenant->membership();
        $organization = $this->tenant->organizationOrFail();

        if ($membership === null || ! ProgramVisibility::canView($membership)
            || ! $this->entitlements->allows($organization, FeatureRegistry::PROGRAMS) || ! $this->entitlements->allows($organization, FeatureRegistry::GROUPS)) {
            return new Collection;
        }
        if ($filters->service !== null || ($filters->status !== null && $filters->status !== AppointmentStatus::Confirmed)) {
            return new Collection;
        }

        $sessions = ProgramSession::query()
            ->with(['program:id,organization_id,name,color,status', 'location:id,organization_id,name', 'facilitator:id,organization_id,user_id,name_prefix', 'facilitator.user:id,name'])
            ->whereNull('cancelled_at')
            ->where('starts_at', '>=', $range->startsAt)->where('starts_at', '<', $range->endsAt)
            ->whereIn('program_id', \App\Models\Program::query()->where('status', '!=', ProgramStatus::Archived->value)->select('programs.id'))
            ->when($filters->program !== null && $filters->program !== 'all', fn ($q) => $q->where('program_id', $filters->program))
            ->when($filters->clinician, fn ($q, $id) => $q->where('facilitator_membership_id', $id))
            ->when($filters->location, fn ($q, $id) => $q->where('location_id', $id))
            ->when($filters->modality, fn ($q, $m) => $q->where('is_online', $m === Modality::Telehealth))
            ->orderBy('starts_at')->orderBy('id')
            ->limit(self::MAX_EVENTS)->get();

        $link = Route::has('app.programs.sessions.show');

        return $sessions->map(function (ProgramSession $s) use ($timezone, $link) {
            $local = fn ($instant) => CarbonImmutable::instance($instant)->setTimezone($timezone);

            return new CalendarEvent(
                id: $s->id,
                start: $local($s->starts_at),
                end: $local($s->ends_at),
                clientName: $s->title,
                serviceName: $s->program->name,
                clinicianId: $s->facilitator_membership_id ?? 'programs',
                clinicianName: $s->facilitator?->professionalName() ?? 'Programs',
                locationLabel: $s->placeLabel(),
                modality: $s->is_online ? Modality::Telehealth : Modality::InPerson,
                status: AppointmentStatus::Confirmed,
                family: self::FAMILY[$s->program->color->value] ?? 'blue',
                zone: $timezone,
                kind: 'program',
                url: $link ? route('app.programs.sessions.show', ['program' => $s->program_id, 'session' => $s->id]) : null,
            );
        })->values();
    }
}
