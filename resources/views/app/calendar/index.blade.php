@php
    $tz = $range->timezone;
    $hasFilters = $filters->isNarrowed();
    $base = fn (array $extra = []) => route('app.calendar.index', $filters->query() + $extra);
    $viewItems = collect(['day' => 'Day', 'week' => 'Week', 'month' => 'Month'])->map(fn ($label, $key) => [
        'label' => $label, 'active' => $filters->view === $key, 'url' => route('app.calendar.index', $filters->query(view: $key)),
    ])->values()->all();
@endphp
<x-layouts.app title="Appointments" :wide="true">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/calendar.css') }}?v={{ filemtime(public_path('css/screens/calendar.css')) }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/calendar.js') }}?v={{ filemtime(public_path('js/calendar.js')) }}" defer></script>
    @endpush

    <div class="cal-layout">
        <div class="cal-main">
            <x-ui.page-header class="cal-header" title="Appointments" description="View and manage your appointments across all locations." icon="calendar" icon-shape="square" icon-tone="blue">
                <x-slot:actions>
                    @if ($canCreate)
                        <x-ui.button icon="plus" class="cal-btn cal-btn--primary" :href="route('app.appointments.create')">New Appointment</x-ui.button>
                    @endif
                    @if ($canManageAvailability)
                        <x-ui.button variant="secondary" class="cal-btn cal-btn--outline" :href="route('app.settings.availability.index')">Add Availability</x-ui.button>
                    @endif
                </x-slot:actions>
            </x-ui.page-header>

            <section class="card cal-card" aria-label="Calendar">
                <form method="GET" action="{{ route('app.calendar.index') }}" class="cal-toolbar" data-cal-toolbar>
                    <input type="hidden" name="view" value="{{ $filters->view }}">
                    <input type="hidden" name="date" value="{{ $filters->date->format('Y-m-d') }}">
                    @foreach (['status' => $filters->status?->value, 'modality' => $filters->modality?->value] as $name => $value)
                        @if ($value !== null)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
                    @endforeach

                    <x-ui.segmented class="cal-segmented" :items="$viewItems" label="Calendar view" />

                    <div class="cal-range" role="group" aria-label="Date range">
                        <a href="{{ $prevUrl }}" class="cal-range__nav" aria-label="Previous {{ $filters->view }}"><x-ui.icon name="chevron-left" :size="16" /></a>
                        <span class="cal-range__label" aria-live="polite">{{ $label }}</span>
                        <label class="cal-range__pick" title="Go to date">
                            <x-ui.icon name="calendar" :size="14" />
                            <span class="sr-only">Go to date</span>
                            <input type="date" value="{{ $filters->date->format('Y-m-d') }}" class="cal-range__date" data-cal-goto tabindex="-1">
                        </label>
                        <a href="{{ $nextUrl }}" class="cal-range__nav" aria-label="Next {{ $filters->view }}"><x-ui.icon name="chevron-right" :size="16" /></a>
                    </div>
                    @if ($todayUrl)<a href="{{ $todayUrl }}" class="cal-today-link">Today</a>@endif

                    <div class="cal-toolbar__filters">
                        <div class="cal-select"><select name="location" data-autosubmit aria-label="Location"><option value="">All Locations</option>@foreach ($options['locations'] as $id => $name)<option value="{{ $id }}" @selected($filters->location === $id)>{{ $name }}</option>@endforeach</select><x-ui.icon name="chevron-down" :size="14" /></div>
                        <div class="cal-select"><select name="clinician" data-autosubmit aria-label="Clinician"><option value="">All Clinicians</option>@foreach ($options['clinicians'] as $id => $name)<option value="{{ $id }}" @selected($filters->clinician === $id)>{{ $name }}</option>@endforeach</select><x-ui.icon name="chevron-down" :size="14" /></div>
                        <div class="cal-select"><select name="service" data-autosubmit aria-label="Service"><option value="">All Services</option>@foreach ($options['services'] as $id => $name)<option value="{{ $id }}" @selected($filters->service === $id)>{{ $name }}</option>@endforeach</select><x-ui.icon name="chevron-down" :size="14" /></div>
                        <button type="button" class="cal-more" data-dialog-open="cal-more-filters" aria-haspopup="dialog"><x-ui.icon name="filter" :size="14" /><span>More filters</span>@if ($filters->status || $filters->modality)<span class="cal-more__dot" aria-label="Active"></span>@endif</button>
                    </div>
                    <noscript><button type="submit" class="cal-more">Apply</button></noscript>
                </form>

                @if ($filters->view === 'week')
                    @include('app.calendar._week')
                @elseif ($filters->view === 'day')
                    @include('app.calendar._day')
                @else
                    @include('app.calendar._month')
                @endif
            </section>

            @include('app.calendar._today')
        </div>

        @include('app.calendar._rail')
    </div>

    <x-ui.modal id="cal-more-filters" title="More filters" size="sm">
        <form method="GET" action="{{ route('app.calendar.index') }}" class="stack" id="cal-more-form">
            <input type="hidden" name="view" value="{{ $filters->view }}">
            <input type="hidden" name="date" value="{{ $filters->date->format('Y-m-d') }}">
            @foreach (['clinician' => $filters->clinician, 'location' => $filters->location, 'service' => $filters->service] as $name => $value)
                @if ($value !== null)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
            @endforeach
            <x-ui.field label="Status" name="status" id="more-status">
                <x-ui.select name="status" id="more-status" :value="$filters->status?->value" placeholder="All Statuses" :options="$statuses" />
            </x-ui.field>
            <x-ui.field label="Modality" name="modality" id="more-modality">
                <x-ui.select name="modality" id="more-modality" :value="$filters->modality?->value" placeholder="In person and telehealth" :options="$modalities" />
            </x-ui.field>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" :href="route('app.calendar.index', ['view' => $filters->view, 'date' => $filters->date->format('Y-m-d')] + array_diff_key($filters->filterQuery(), ['status' => 1, 'modality' => 1]))">Clear these</x-ui.button>
            <x-ui.button type="submit" form="cal-more-form">Apply</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-layouts.app>
