@php
    $miniStart = $mini['start'];
    $selected = $filters->date->format('Y-m-d');
    $dayHref = fn (string $date) => route('app.calendar.index', $filters->query(date: $date));
    $quick = array_filter([
        $canCreate ? ['calendar', 'Create Appointment', route('app.appointments.create')] : null,
        $canManageAvailability ? ['clock', 'Add Availability', route('app.settings.availability.index')] : null,
        $canCreateClient ? ['users', 'Create Client', route('app.clients.create')] : null,
        \Illuminate\Support\Facades\Route::has('app.messages.create') ? ['message-square', 'Send Message', route('app.messages.create')] : null,
        \Illuminate\Support\Facades\Route::has('app.tasks.create') ? ['square-check', 'Create Task', route('app.tasks.create')] : null,
        \Illuminate\Support\Facades\Route::has('app.invoices.create') ? ['file-text', 'Create Invoice', route('app.invoices.create')] : null,
    ]);
@endphp
<aside class="cal-rail" aria-label="Calendar tools">
    <section class="card cal-mini" aria-labelledby="cal-mini-title">
        <header class="cal-rail__head"><x-ui.icon name="calendar" :size="20" /><h2 id="cal-mini-title">Calendar</h2></header>
        <div class="cal-mini__nav">
            <a href="{{ $mini['prev'] }}" aria-label="Previous month"><x-ui.icon name="chevron-left" :size="16" /></a>
            <span class="cal-mini__month">{{ $mini['month']->format('F Y') }}</span>
            <a href="{{ $mini['next'] }}" aria-label="Next month"><x-ui.icon name="chevron-right" :size="16" /></a>
        </div>
        <div class="cal-mini__grid" role="grid" aria-label="{{ $mini['month']->format('F Y') }}">
            @for ($i = 0; $i < 7; $i++)<span class="cal-mini__dow" aria-hidden="true">{{ $miniStart->addDays($i)->format('D') }}</span>@endfor
            @for ($i = 0; $i < $mini['weeks'] * 7; $i++)
                @php($day = $miniStart->addDays($i))
                @php($date = $day->format('Y-m-d'))
                @php($family = $mini['marked'][$date] ?? null)
                <a href="{{ $dayHref($date) }}" @class(['cal-mini__day', 'is-outside' => $day->month !== $mini['month']->month, 'is-today' => $date === $today, 'is-selected' => $date === $selected]) @if ($date === $selected) aria-current="date" @endif aria-label="{{ $day->format('F j, Y') }}{{ $family ? ', has appointments' : '' }}">
                    <span>{{ $day->day }}</span>@if ($family)<i class="cal-mini__dot cal-mini__dot--{{ $family }}" aria-hidden="true"></i>@endif
                </a>
            @endfor
        </div>
    </section>

    @if ($quick !== [])
        <section class="card cal-quick" aria-labelledby="cal-quick-title">
            <header class="cal-rail__head"><x-ui.icon name="badge-check" :size="20" /><h2 id="cal-quick-title">Quick Actions</h2></header>
            <ul class="cal-quick__list">
                @foreach ($quick as [$icon, $text, $url])
                    <li><a href="{{ $url }}"><x-ui.icon :name="$icon" :size="19" /><span>{{ $text }}</span></a></li>
                @endforeach
            </ul>
        </section>
    @endif

    <form method="GET" action="{{ route('app.calendar.index') }}" class="card cal-filters" aria-label="Filters">
        <input type="hidden" name="view" value="{{ $filters->view }}">
        <input type="hidden" name="date" value="{{ $selected }}">
        @if ($filters->modality)<input type="hidden" name="modality" value="{{ $filters->modality->value }}">@endif
        <header class="cal-rail__head">
            <x-ui.icon name="filter" :size="18" /><h2>Filters</h2>
            @if ($hasFilters)<a href="{{ route('app.calendar.index', ['view' => $filters->view, 'date' => $selected]) }}" class="cal-filters__clear">Clear all</a>@endif
        </header>
        @foreach ([
            ['location', 'Location', 'All Locations', $options['locations'], $filters->location],
            ['clinician', 'Clinician', 'All Clinicians', $options['clinicians'], $filters->clinician],
            ['service', 'Service', 'All Services', $options['services'], $filters->service],
            ['status', 'Status', 'All Statuses', $statuses, $filters->status?->value],
        ] as [$name, $text, $all, $choices, $current])
            <div class="cal-filters__field">
                <label for="rail-{{ $name }}">{{ $text }}</label>
                <div class="cal-select cal-select--rail"><select id="rail-{{ $name }}" name="{{ $name }}" data-autosubmit><option value="">{{ $all }}</option>@foreach ($choices as $id => $choice)<option value="{{ $id }}" @selected($current === (string) $id)>{{ $choice }}</option>@endforeach</select><x-ui.icon name="chevron-down" :size="14" /></div>
            </div>
        @endforeach
        <noscript><button type="submit" class="cal-more">Apply</button></noscript>
    </form>
</aside>
