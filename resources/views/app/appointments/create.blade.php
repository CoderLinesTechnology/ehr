@php
    $params = array_filter([
        'client' => $client?->id, 'service' => $service?->id, 'clinician' => $clinician?->id,
        'modality' => $modality?->value, 'location' => $location?->id, 'date' => $date, 'time' => $time,
    ]);
    $tz = tenant()->organizationOrFail()->timezone;
    $placeTz = $location?->timezone ?? $tz;
@endphp
<x-layouts.app title="New appointment">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/calendar.css') }}?v={{ filemtime(public_path('css/screens/calendar.css')) }}">
    @endpush

    <x-ui.page-header title="New appointment" description="Choose who, what and when. Free times come from the clinician's availability." icon="calendar-plus" icon-shape="square" icon-tone="blue">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs :items="[['label' => 'Appointments', 'url' => route('app.calendar.index')], ['label' => 'New appointment']]" />
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    <div class="appt-stack">
        <form method="GET" action="{{ route('app.appointments.create') }}" class="card appt-card" aria-label="Find a time">
            <h2 class="appt-card__title">1. Who and what</h2>
            <div class="form-grid">
                <div class="form-grid__full">
                    @if ($client)
                        <input type="hidden" name="client" value="{{ $client->id }}">
                        <p class="appt-client"><x-ui.avatar :name="$client->displayName()" size="sm" :decorative="true" /> <strong>{{ $client->displayName() }}</strong> <span class="text-muted">{{ $client->formattedNumber() }}</span>
                            <a href="{{ route('app.appointments.create', array_diff_key($params, ['client' => 1])) }}">Change</a></p>
                    @else
                        <x-ui.field label="Client" name="q" help="Search by name, email, phone or client number.">
                            <div class="appt-search">
                                <x-ui.input type="search" name="q" :value="$term" icon="search" placeholder="Search clients" autocomplete="off" />
                                <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
                            </div>
                        </x-ui.field>
                        @if ($term !== '')
                            @if ($matches->isEmpty())
                                <p class="text-muted text-sm">No client matches. They may be archived, or not yours to see.</p>
                            @else
                                <div class="option-list" data-choice-group="1" role="radiogroup" aria-label="Matching clients">
                                    @foreach ($matches as $match)
                                        <div class="choice">
                                            <input type="radio" class="choice__input" name="client" id="client-{{ $match->id }}" value="{{ $match->id }}" @checked($loop->first && $matches->count() === 1)>
                                            <label class="choice__label" for="client-{{ $match->id }}">{{ $match->displayName() }} <span class="choice__help">{{ $match->formattedNumber() }}</span></label>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    @endif
                </div>

                <x-ui.field label="Service" name="service" id="f-service">
                    <x-ui.select name="service" id="f-service" :value="$service?->id" placeholder="Choose a service" :options="$services->pluck('name', 'id')->all()" />
                </x-ui.field>
                <x-ui.field label="Clinician" name="clinician" id="f-clinician" :help="$service ? 'Only clinicians who provide this service.' : null">
                    <x-ui.select name="clinician" id="f-clinician" :value="$clinician?->id" placeholder="Choose a clinician" :options="$clinicians->mapWithKeys(fn ($m) => [$m->id => $m->professionalName()])->all()" />
                </x-ui.field>
                <x-ui.field label="Modality" name="modality" id="f-modality">
                    <x-ui.select name="modality" id="f-modality" :value="$modality?->value" placeholder="Choose" :options="collect($modalities)->mapWithKeys(fn ($m) => [$m->value => $m->label()])->all()" />
                </x-ui.field>
                @if ($modality === \App\Domain\Scheduling\Modality::InPerson || $modality === null)
                    <x-ui.field label="Location" name="location" id="f-location">
                        <x-ui.select name="location" id="f-location" :value="$location?->id" placeholder="Any location" :options="$locations->pluck('name', 'id')->all()" />
                    </x-ui.field>
                @endif
                <x-ui.field label="Date" name="date" id="f-date">
                    <x-ui.input type="date" name="date" id="f-date" :value="$date" />
                </x-ui.field>
                <x-ui.field label="Start time" name="time" id="f-time" optional help="Pick a free time below, or type one.">
                    <x-ui.input type="time" name="time" id="f-time" :value="$time" step="300" />
                </x-ui.field>
            </div>
            <div class="form-actions"><x-ui.button type="submit" icon="search">Show available times</x-ui.button></div>
        </form>

        @if ($slotDays !== null)
            <section class="card appt-card" aria-labelledby="slots-title">
                <h2 class="appt-card__title" id="slots-title">2. Available times <span class="text-muted">(next {{ 7 }} days from {{ fmt()->date($date) }})</span></h2>
                @forelse ($slotDays as $day => $slots)
                    <div class="appt-slotday">
                        <h3>{{ \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC')->format('l, j F') }}</h3>
                        <ul class="appt-slots">
                            @foreach ($slots as $slot)
                                @php($url = route('app.appointments.create', array_filter(array_merge($params, ['date' => $day, 'time' => $slot->localStart()->format('H:i'), 'location' => $slot->locationId, 'modality' => $slot->modality->value]))))
                                <li><a href="{{ $url }}" @class(['appt-slot', 'is-chosen' => $time === $slot->localStart()->format('H:i') && $date === $day])>{{ fmt()->time($slot->startsAt, $slot->timezone) }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <p class="text-muted">No free times in this week. <a href="{{ route('app.appointments.create', array_merge($params, ['date' => \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')->addDays(7)->format('Y-m-d'), 'time' => null])) }}">Look at the following week</a>, or book outside the clinician's availability by entering a time above.</p>
                @endforelse
            </section>
        @endif

        @if ($ready)
            <form method="POST" action="{{ route('app.appointments.store') }}" class="card appt-card form" data-submit-once aria-labelledby="confirm-title">
                @csrf
                <h2 class="appt-card__title" id="confirm-title">3. Confirm</h2>
                @if ($errors->any())
                    <x-ui.alert tone="danger" title="This could not be booked">
                        <ul>@foreach (array_unique($errors->all()) as $message)<li>{{ $message }}</li>@endforeach</ul>
                    </x-ui.alert>
                @endif
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="service_id" value="{{ $service->id }}">
                <input type="hidden" name="clinician_id" value="{{ $clinician->id }}">
                <input type="hidden" name="modality" value="{{ $modality->value }}">
                @if ($location)<input type="hidden" name="location_id" value="{{ $location->id }}">@endif
                <input type="hidden" name="date" value="{{ $date }}">
                <input type="hidden" name="time" value="{{ $time }}">
                <x-ui.dl>
                    <x-ui.dl-item label="Client">{{ $client->displayName() }}</x-ui.dl-item>
                    <x-ui.dl-item label="Service">{{ $service->name }} · {{ $service->duration_minutes }} min</x-ui.dl-item>
                    <x-ui.dl-item label="Clinician">{{ $clinician->professionalName() }}</x-ui.dl-item>
                    <x-ui.dl-item label="When">{{ \Carbon\CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, $placeTz)->format('l, j F Y') }} at {{ \Carbon\CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, $placeTz)->format(fmt()->timeFormat()) }}{{ fmt()->zoneSuffix($placeTz) }}</x-ui.dl-item>
                    <x-ui.dl-item label="Where">{{ $modality === \App\Domain\Scheduling\Modality::Telehealth ? 'Telehealth (online)' : $location->name }}</x-ui.dl-item>
                </x-ui.dl>
                <x-ui.field label="Scheduling note" name="scheduling_notes" optional help="Visible to staff who can see this appointment. Do not put clinical notes here.">
                    <x-ui.textarea name="scheduling_notes" rows="3" maxlength="2000" />
                </x-ui.field>
                @if ($canOverbook)
                    <x-ui.checkbox name="allow_overlap" label="Book even if it overlaps another appointment" help="Double-booking is recorded on the appointment." />
                @endif
                <div class="form-actions">
                    <x-ui.button type="submit" icon="check">Book appointment</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('app.calendar.index')">Cancel</x-ui.button>
                </div>
            </form>
        @endif
    </div>
</x-layouts.app>
