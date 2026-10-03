<?php

namespace App\Http\Controllers\App\Scheduling;

use App\Domain\Clients\ClientSearch;
use App\Domain\Clients\ClientStatus;
use App\Domain\Clients\ClientVisibility;
use App\Domain\Scheduling\AppointmentDetailsData;
use App\Domain\Scheduling\AppointmentSource;
use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\CancellationKind;
use App\Domain\Scheduling\Modality;
use App\Domain\Scheduling\RescheduleAppointment;
use App\Domain\Scheduling\RescheduleAppointmentData;
use App\Domain\Scheduling\ScheduleAppointment;
use App\Domain\Scheduling\ScheduleAppointmentData;
use App\Domain\Scheduling\Slot;
use App\Domain\Scheduling\SlotFinder;
use App\Domain\Scheduling\SlotQuery;
use App\Domain\Scheduling\TransitionAppointment;
use App\Domain\Scheduling\UpdateAppointmentDetails;
use App\Domain\Shared\DomainException;
use App\Http\Requests\Scheduling\RescheduleAppointmentRequest;
use App\Http\Requests\Scheduling\StoreAppointmentRequest;
use App\Http\Requests\Scheduling\TransitionAppointmentRequest;
use App\Http\Requests\Scheduling\UpdateAppointmentRequest;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Booking, the appointment page and its actions. Rules live in app/Domain/Scheduling; this class
 * authorizes (route middleware + form requests), maps form fields to domain input, fixes the booking
 * `source` for the route (staff, never from input) and the actor (the signed-in user), and presents
 * the result. Domain refusals come back as field errors (MapsDomainErrors).
 */
final class AppointmentController
{
    use MapsDomainErrors;

    /** Booking-screen limits: nothing here is unbounded. */
    private const CLIENT_MATCHES = 12;

    private const SLOT_DAYS = 7;

    private const MAX_SLOTS = 60;

    public function create(Request $request, SlotFinder $finder): View
    {
        $membership = tenant()->membership();
        $uuid = static fn (string $key): ?string => is_string($request->query($key)) && Str::isUuid($request->query($key)) ? strtolower($request->query($key)) : null;

        $clients = ClientVisibility::apply(Client::query(), $membership)->where('status', '!=', ClientStatus::Archived->value);
        $client = $uuid('client') ? (clone $clients)->find($uuid('client')) : null;

        $term = trim((string) $request->query('q', ''));
        $matches = $client === null && $term !== ''
            ? ClientSearch::apply(clone $clients, $term)->orderBy('last_name')->orderBy('first_name')->limit(self::CLIENT_MATCHES)
                ->get(['id', 'organization_id', 'first_name', 'last_name', 'preferred_name', 'client_number'])
            : collect();

        $services = Service::query()->active()->orderBy('sort')->orderBy('name')->limit(200)->get();
        $service = $services->firstWhere('id', $uuid('service'));

        $clinicianQuery = $service !== null
            ? $service->providers()->where('organization_memberships.status', 'active')->where('is_provider', true)
            : OrganizationMembership::query()->providers();
        $clinicians = $clinicianQuery->with('user:id,name')->limit(100)->get()
            ->sortBy(fn ($m) => mb_strtolower($m->displayName()));
        $clinician = $clinicians->firstWhere('id', $uuid('clinician'));

        $modality = Modality::tryFrom((string) $request->query('modality', ''));
        if ($service !== null && ($modality === null || ! $service->allows($modality)) && count($service->modalities()) === 1) {
            $modality = $service->modalities()[0];
        }

        $locations = Location::query()->active()->orderBy('sort')->orderBy('name')->limit(100)->get();
        $location = $modality === Modality::InPerson ? $locations->firstWhere('id', $uuid('location')) : null;

        $date = $this->date($request->query('date'));
        $time = is_string($request->query('time')) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $request->query('time')) === 1 ? $request->query('time') : null;

        return view('app.appointments.create', [
            'client' => $client,
            'matches' => $matches,
            'term' => $term,
            'services' => $services,
            'service' => $service,
            'clinicians' => $clinicians,
            'clinician' => $clinician,
            'modality' => $modality,
            'modalities' => $service ? $service->modalities() : Modality::cases(),
            'locations' => $locations,
            'location' => $location,
            'date' => $date,
            'time' => $time,
            'slotDays' => $service !== null && $clinician !== null ? $this->slotsByDay($finder, $service, $clinician, $location, $modality, $date) : null,
            'canOverbook' => Gate::allows('overbook', Appointment::class),
            'ready' => $client && $service && $clinician && $modality && $date && $time && ($modality === Modality::Telehealth || $location),
        ]);
    }

    public function store(StoreAppointmentRequest $request, ScheduleAppointment $schedule): RedirectResponse
    {
        $appointment = $this->attempt(fn () => $schedule(new ScheduleAppointmentData(
            client: $request->client,
            service: $request->service,
            clinician: $request->clinician,
            modality: $request->modality(),
            startsAt: $request->startsAt(),
            location: $request->location,
            source: AppointmentSource::Staff, // fixed for this route: never from input
            allowOverlap: $request->allowOverlap(),
            schedulingNotes: $request->notes(),
            actor: $request->user(),
        )), ['starts_at' => 'time', 'clinician_membership_id' => 'clinician_id']);

        return redirect()->route('app.appointments.show', ['appointment' => $appointment])
            ->with('success', 'Appointment booked for '.$request->client->displayName().'.');
    }

    public function show(Request $request, Appointment $appointment, SlotFinder $finder): View
    {
        $appointment->load([
            'client:id,organization_id,first_name,last_name,preferred_name,client_number,record_environment,primary_clinician_membership_id',
            'service',
            'clinician:id,organization_id,user_id,name_prefix,title,color',
            'clinician.user:id,name',
            'location',
            'rescheduledFrom:id,organization_id,starts_at,timezone',
            'rescheduledTo:id,organization_id,rescheduled_from_id,starts_at,timezone',
        ]);
        $history = $appointment->statusHistory()->with('actor:id,name')->limit(100)->get();

        $canEdit = Gate::allows('update', $appointment);
        $canCancel = Gate::allows('cancel', $appointment);
        $now = CarbonImmutable::now();
        $actions = collect($appointment->status->allowedTransitions())
            ->filter(fn (AppointmentStatus $to) => in_array($to, [AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true) ? $canCancel : $canEdit)
            ->map(fn (AppointmentStatus $to) => ['status' => $to, 'ready' => $to->canEnterAt($appointment->starts_at, $now)])
            ->values();

        $reschedulable = $canEdit && $appointment->status->canReschedule();
        $rescheduleClinicians = $reschedulable
            ? OrganizationMembership::query()->providers()->with('user:id,name')->limit(100)->get()->sortBy(fn ($m) => mb_strtolower($m->displayName()))
            : collect();
        $slotClinician = $rescheduleClinicians->firstWhere('id', $request->query('clinician')) ?? $rescheduleClinicians->firstWhere('id', $appointment->clinician_membership_id);
        $from = $this->date($request->query('from')) ?? max($now->setTimezone($appointment->timezone)->format('Y-m-d'), $appointment->localStart()->format('Y-m-d'));

        return view('app.appointments.show', [
            'appointment' => $appointment,
            'history' => $history,
            'actions' => $actions,
            'canEdit' => $canEdit,
            'canClientLink' => $appointment->client !== null && Gate::allows('view', $appointment->client),
            'reschedulable' => $reschedulable,
            'rescheduleClinicians' => $rescheduleClinicians,
            'slotDays' => $reschedulable && $slotClinician !== null
                ? $this->slotsByDay($finder, $appointment->service, $slotClinician, $appointment->location, $appointment->modality, $from)
                : null,
            'slotClinicianId' => $slotClinician?->id,
            'from' => $from,
            'locations' => $canEdit ? Location::query()->active()->orderBy('sort')->orderBy('name')->limit(100)->pluck('name', 'id')->all() : [],
            'canOverbook' => Gate::allows('overbook', Appointment::class),
            'cancellationKinds' => collect(CancellationKind::cases())->mapWithKeys(fn ($k) => [$k->value => $k->label()])->all(),
        ]);
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment, UpdateAppointmentDetails $update): RedirectResponse
    {
        $this->attempt(fn () => $update($appointment, new AppointmentDetailsData(
            schedulingNotes: $request->notes(),
            modality: Modality::from($request->input('modality')),
            location: $request->location,
            actor: $request->user(),
        )));

        return redirect()->route('app.appointments.show', ['appointment' => $appointment])->with('success', 'Appointment details saved.');
    }

    public function transition(TransitionAppointmentRequest $request, Appointment $appointment, TransitionAppointment $transition): RedirectResponse
    {
        $to = $request->status();
        $this->attempt(fn () => $transition(
            $appointment, $to, $request->user(), $request->input('reason'),
            $to === AppointmentStatus::Cancelled ? $request->input('cancellation_kind') : null,
        ));

        return redirect()->route('app.appointments.show', ['appointment' => $appointment])
            ->with('success', 'Appointment marked '.Str::lower($to->label()).'.');
    }

    public function reschedule(RescheduleAppointmentRequest $request, Appointment $appointment, RescheduleAppointment $reschedule): RedirectResponse
    {
        $replacement = $this->attempt(fn () => $reschedule(new RescheduleAppointmentData(
            appointment: $appointment,
            startsAt: $request->startsAt($appointment),
            clinician: $request->clinician,
            source: AppointmentSource::Staff, // fixed for this route: never from input
            allowOverlap: $request->allowOverlap(),
            reason: $request->input('reason'),
            actor: $request->user(),
        )), ['starts_at' => 'time', 'clinician_membership_id' => 'clinician_id']);

        return redirect()->route('app.appointments.show', ['appointment' => $replacement])->with('success', 'Appointment rescheduled.');
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        return $parsed && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * Free start times for the week from $from (staff view: no online-booking rules).
     *
     * @return array<string, list<Slot>>|null local date => slots, null when there is nothing to search yet
     */
    private function slotsByDay(SlotFinder $finder, Service $service, OrganizationMembership $clinician, ?Location $location, ?Modality $modality, ?string $from): ?array
    {
        if ($from === null) {
            return null;
        }

        try {
            $result = $finder->find(new SlotQuery(
                service: $service,
                from: $from,
                to: CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC')->addDays(self::SLOT_DAYS - 1)->format('Y-m-d'),
                clinician: $clinician,
                location: $location,
                modality: $modality,
            ));
        } catch (DomainException) {
            return [];
        }

        $days = [];
        foreach (array_slice($result->slots, 0, self::MAX_SLOTS) as $slot) {
            $days[$slot->localDate()][] = $slot;
        }

        return $days;
    }
}
