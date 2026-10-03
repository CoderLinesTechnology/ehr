@php
    $a = $appointment;
    $telehealth = $a->modality === \App\Domain\Scheduling\Modality::Telehealth;
    $source = \App\Domain\Scheduling\AppointmentSource::tryFrom((string) $a->source)?->label();
    $when = fmt()->dateTime($a->starts_at, $a->timezone).' – '.fmt()->time($a->ends_at, $a->timezone).fmt()->zoneSuffix($a->timezone);
    $clientName = $a->client->displayName();
@endphp
<x-layouts.app :title="'Appointment · '.$clientName">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/calendar.css') }}?v={{ filemtime(public_path('css/screens/calendar.css')) }}">
    @endpush

    <x-ui.page-header :title="$clientName" :description="$a->service->name.' · '.fmt()->dateTime($a->starts_at, $a->timezone)" icon="calendar" icon-shape="square" icon-tone="blue">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs :items="[['label' => 'Appointments', 'url' => route('app.calendar.index', ['date' => $a->localStart()->format('Y-m-d')])], ['label' => 'Appointment']]" />
        </x-slot:breadcrumbs>
        <x-slot:meta>
            <x-ui.badge :tone="$a->status->badgeTone()">{{ $a->status->badgeLabel() }}</x-ui.badge>
            @if ($a->isDemo())<x-ui.badge tone="demo">Demo</x-ui.badge>@endif
            @if ($a->late_cancellation)<x-ui.badge tone="warning">Late cancellation</x-ui.badge>@endif
        </x-slot:meta>
        <x-slot:actions>
            @foreach ($actions as $action)
                @php($to = $action['status'])
                @if ($action['ready'])
                    @if ($to === \App\Domain\Scheduling\AppointmentStatus::Cancelled)
                        <x-ui.confirm-form :action="route('app.appointments.transition', ['appointment' => $a])" title="Cancel this appointment?"
                            message="The time is released. This cannot be undone; book a new appointment if the client comes back." confirm-label="Cancel appointment" cancel-label="Keep it"
                            button-label="Cancel appointment" reason-field="reason" reason-label="Reason (optional)">
                            <input type="hidden" name="status" value="cancelled">
                            <x-ui.field label="Cancelled by" name="cancellation_kind" required>
                                <x-ui.select name="cancellation_kind" placeholder="Choose" :options="$cancellationKinds" required />
                            </x-ui.field>
                        </x-ui.confirm-form>
                    @else
                        <x-ui.confirm-form :action="route('app.appointments.transition', ['appointment' => $a])" :title="$to->actionLabel().'?'"
                            :message="'Mark this appointment as '.\Illuminate\Support\Str::lower($to->label()).'.'" :confirm-label="$to->actionLabel()"
                            tone="primary" :button-label="$to->actionLabel()" :button-variant="$loop->first ? 'primary' : 'secondary'"
                            :reason-field="$to === \App\Domain\Scheduling\AppointmentStatus::NoShow ? 'reason' : null" reason-label="Note (optional)">
                            <input type="hidden" name="status" value="{{ $to->value }}">
                        </x-ui.confirm-form>
                    @endif
                @endif
            @endforeach
        </x-slot:actions>
    </x-ui.page-header>

    <div class="appt-show">
        <div class="appt-stack">
            <x-ui.card title="Details">
                <x-ui.dl>
                    <x-ui.dl-item label="Client">
                        @if ($canClientLink)<a href="{{ route('app.clients.show', ['client' => $a->client]) }}">{{ $clientName }}</a> <span class="text-muted">{{ $a->client->formattedNumber() }}</span>@else{{ $clientName }}@endif
                    </x-ui.dl-item>
                    <x-ui.dl-item label="Service">{{ $a->service->name }} · {{ $a->durationMinutes() }} min</x-ui.dl-item>
                    <x-ui.dl-item label="Clinician">{{ $a->clinician->professionalName() }}@if ($a->clinician->title), {{ $a->clinician->title }}@endif</x-ui.dl-item>
                    <x-ui.dl-item label="When">{{ $when }}</x-ui.dl-item>
                    <x-ui.dl-item label="Where">{{ $telehealth ? 'Telehealth (online)' : ($a->location?->name ?? '—') }}</x-ui.dl-item>
                    <x-ui.dl-item label="Fee">{{ fmt()->money($a->price_minor, $a->currency) }}</x-ui.dl-item>
                    <x-ui.dl-item label="Booked via">{{ $source }}@if ($a->allow_overlap) <x-ui.badge tone="warning">Double-booked</x-ui.badge>@endif</x-ui.dl-item>
                    @if ($a->rescheduledFrom)
                        <x-ui.dl-item label="Moved from"><a href="{{ route('app.appointments.show', ['appointment' => $a->rescheduledFrom]) }}">{{ fmt()->dateTime($a->rescheduledFrom->starts_at, $a->rescheduledFrom->timezone) }}</a></x-ui.dl-item>
                    @endif
                    @if ($a->rescheduledTo)
                        <x-ui.dl-item label="Moved to"><a href="{{ route('app.appointments.show', ['appointment' => $a->rescheduledTo]) }}">{{ fmt()->dateTime($a->rescheduledTo->starts_at, $a->rescheduledTo->timezone) }}</a></x-ui.dl-item>
                    @endif
                    @if ($a->cancellation_kind)
                        <x-ui.dl-item label="Cancelled by">{{ \App\Domain\Scheduling\CancellationKind::tryFrom((string) $a->cancellation_kind)?->label() }}</x-ui.dl-item>
                    @endif
                    @if ($a->cancellation_reason)<x-ui.dl-item label="Reason" :wide="true">{{ $a->cancellation_reason }}</x-ui.dl-item>@endif
                    <x-ui.dl-item label="Scheduling note" :wide="true">{{ $a->scheduling_notes }}</x-ui.dl-item>
                </x-ui.dl>
            </x-ui.card>

            <x-ui.card title="Status history">
                <ol class="appt-history">
                    @foreach ($history as $h)
                        <li>
                            <span class="appt-history__what">
                                @if ($h->from_status)<x-ui.badge :tone="$h->from_status->badgeTone()">{{ $h->from_status->badgeLabel() }}</x-ui.badge> <x-ui.icon name="chevron-right" :size="14" />@endif
                                <x-ui.badge :tone="$h->to_status->badgeTone()">{{ $h->to_status->badgeLabel() }}</x-ui.badge>
                            </span>
                            <span class="text-muted text-sm">{{ fmt()->dateTime($h->occurred_at) }}@if ($h->actor) · {{ $h->actor->name }}@endif</span>
                            @if ($h->reason)<span class="text-sm">{{ $h->reason }}</span>@endif
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>
        </div>

        @if ($canEdit)
            <div class="appt-stack">
                @if ($reschedulable)
                    <section class="card appt-card" id="reschedule" aria-labelledby="resched-title">
                        <h2 class="appt-card__title" id="resched-title">Reschedule</h2>
                        <form method="GET" action="{{ route('app.appointments.show', ['appointment' => $a]) }}#reschedule" class="appt-inline">
                            <x-ui.field label="Clinician" name="clinician" id="rs-clin">
                                <x-ui.select name="clinician" id="rs-clin" :value="$slotClinicianId" :options="$rescheduleClinicians->mapWithKeys(fn ($m) => [$m->id => $m->professionalName()])->all()" />
                            </x-ui.field>
                            <x-ui.field label="Free times from" name="from" id="rs-from">
                                <x-ui.input type="date" name="from" id="rs-from" :value="$from" />
                            </x-ui.field>
                            <x-ui.button type="submit" variant="secondary">Show times</x-ui.button>
                        </form>
                        @if ($slotDays !== null)
                            @forelse ($slotDays as $day => $slots)
                                <div class="appt-slotday">
                                    <h3>{{ \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC')->format('D, j M') }}</h3>
                                    <ul class="appt-slots">
                                        @foreach ($slots as $slot)
                                            <li><a href="{{ route('app.appointments.show', ['appointment' => $a, 'clinician' => $slotClinicianId, 'from' => $from, 'date' => $day, 'time' => $slot->localStart()->format('H:i')]) }}#reschedule" @class(['appt-slot', 'is-chosen' => request('date') === $day && request('time') === $slot->localStart()->format('H:i')])>{{ fmt()->time($slot->startsAt, $slot->timezone) }}</a></li>
                                        @endforeach
                                    </ul>
                                </div>
                            @empty
                                <p class="text-muted text-sm">No free times that week.</p>
                            @endforelse
                        @endif
                        <form method="POST" action="{{ route('app.appointments.reschedule', ['appointment' => $a]) }}" class="form" data-submit-once>
                            @csrf
                            <input type="hidden" name="clinician_id" value="{{ $slotClinicianId }}">
                            <div class="form-grid">
                                <x-ui.field label="New date" name="date" id="rs-date" required><x-ui.input type="date" name="date" id="rs-date" :value="request('date', $a->localStart()->format('Y-m-d'))" required /></x-ui.field>
                                <x-ui.field label="New time" name="time" id="rs-time" required><x-ui.input type="time" name="time" id="rs-time" step="300" :value="request('time', $a->localStart()->format('H:i'))" required /></x-ui.field>
                            </div>
                            <x-ui.field label="Reason" name="reason" optional><x-ui.textarea name="reason" rows="2" maxlength="500" /></x-ui.field>
                            @if ($canOverbook)<x-ui.checkbox name="allow_overlap" label="Allow overlapping another appointment" />@endif
                            <p class="text-muted text-sm">Times are in {{ $a->timezone }}. The old appointment is closed as “rescheduled” and a new one is booked.</p>
                            <div class="form-actions"><x-ui.button type="submit">Reschedule</x-ui.button></div>
                        </form>
                    </section>
                @endif

                @unless ($a->status->isTerminal())
                    <section class="card appt-card" aria-labelledby="edit-title">
                        <h2 class="appt-card__title" id="edit-title">Edit details</h2>
                        <form method="POST" action="{{ route('app.appointments.update', ['appointment' => $a]) }}" class="form" data-submit-once>
                            @csrf @method('PUT')
                            <div class="form-grid">
                                <x-ui.field label="Modality" name="modality" id="ed-mod">
                                    <x-ui.select name="modality" id="ed-mod" :value="$a->modality->value" :options="collect($a->service->modalities())->mapWithKeys(fn ($m) => [$m->value => $m->label()])->all()" />
                                </x-ui.field>
                                <x-ui.field label="Location" name="location_id" id="ed-loc" help="Used for in-person appointments.">
                                    <x-ui.select name="location_id" id="ed-loc" :value="$a->location_id" placeholder="None (telehealth)" :options="$locations" />
                                </x-ui.field>
                            </div>
                            <x-ui.field label="Scheduling note" name="scheduling_notes" optional><x-ui.textarea name="scheduling_notes" rows="3" maxlength="2000" :value="$a->scheduling_notes" /></x-ui.field>
                            <div class="form-actions"><x-ui.button type="submit" variant="secondary">Save details</x-ui.button></div>
                        </form>
                    </section>
                @endunless
            </div>
        @endif
    </div>
</x-layouts.app>
