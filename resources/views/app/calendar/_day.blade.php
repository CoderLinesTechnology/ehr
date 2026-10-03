@php
    $rows = $endHour - $startHour;
    $hourLabel = fn (int $h) => \Carbon\CarbonImmutable::create(2000, 1, 1, $h % 24, 0, 0, 'UTC')->format(fmt()->timeFormat());
    $isToday = $filters->date->format('Y-m-d') === $today;
    $dayUrl = $canCreate ? route('app.appointments.create') : null;
@endphp
<div class="cal-scroll" data-cal-scroll>
    <div class="cal-grid cal-grid--day" style="--rows: {{ $rows }}; --cols: {{ max(1, count($columns)) }}" @if ($dayUrl) data-create-url="{{ $dayUrl }}" @endif data-start-hour="{{ $startHour }}">
        <div class="cal-head cal-head--gutter" aria-hidden="true"></div>
        @forelse ($columns as $column)
            <div @class(['cal-head', 'is-today' => $isToday]) role="columnheader">
                <span class="cal-head__name">{{ $column['name'] }}</span>
                <span class="cal-head__date">{{ count($column['placed']) }} {{ \Illuminate\Support\Str::plural('appointment', count($column['placed'])) }}</span>
            </div>
        @empty
            <div class="cal-head"><span class="cal-head__name">No clinicians</span></div>
        @endforelse

        <div class="cal-gutter" aria-hidden="true">
            @for ($h = $startHour; $h < $endHour; $h++)<span class="cal-hour">{{ $hourLabel($h) }}</span>@endfor
        </div>
        @forelse ($columns as $column)
            <div @class(['cal-col', 'is-today' => $isToday]) data-date="{{ $filters->date->format('Y-m-d') }}" data-clinician="{{ $column['clinicianId'] }}" role="group" aria-label="{{ $column['name'] }}">
                @foreach ($column['placed'] as $item)
                    @include('app.calendar._event', ['event' => $item['event'], 'place' => $item])
                @endforeach
                @if ($column['before'] || $column['after'])<span class="cal-outside">{{ $column['before'] + $column['after'] }} outside hours</span>@endif
            </div>
        @empty
            <div class="cal-col"></div>
        @endforelse
    </div>
</div>
