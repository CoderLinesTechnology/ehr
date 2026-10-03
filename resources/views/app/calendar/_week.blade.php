@php
    $rows = $endHour - $startHour;
    $hourLabel = fn (int $h) => \Carbon\CarbonImmutable::create(2000, 1, 1, $h % 24, 0, 0, 'UTC')->format(fmt()->timeFormat());
    $create = $canCreate ? route('app.appointments.create') : null;
@endphp
<div class="cal-scroll" data-cal-scroll>
    <div class="cal-grid cal-grid--week" style="--rows: {{ $rows }}; --start: {{ $startHour * 60 }}" @if ($create) data-create-url="{{ $create }}" @endif data-start-hour="{{ $startHour }}">
        <div class="cal-head cal-head--gutter" aria-hidden="true"></div>
        @foreach ($columns as $column)
            <div @class(['cal-head', 'is-today' => $column['isToday']]) role="columnheader">
                <span class="cal-head__name">{{ $column['date']->format('D') }}</span>
                <span class="cal-head__date">{{ $column['date']->format('M j') }}</span>
            </div>
        @endforeach

        <div class="cal-gutter" aria-hidden="true">
            @for ($h = $startHour; $h < $endHour; $h++)<span class="cal-hour">{{ $hourLabel($h) }}</span>@endfor
        </div>
        @foreach ($columns as $column)
            <div @class(['cal-col', 'is-today' => $column['isToday']]) data-date="{{ $column['date']->format('Y-m-d') }}" role="group" aria-label="{{ $column['date']->format('l, F j') }}">
                @foreach ($column['placed'] as $item)
                    @include('app.calendar._event', ['event' => $item['event'], 'place' => $item])
                @endforeach
                @if ($column['before'] || $column['after'])
                    <a href="{{ route('app.calendar.index', $filters->query(view: 'day', date: $column['date']->format('Y-m-d'))) }}" class="cal-outside">{{ $column['before'] + $column['after'] }} outside hours</a>
                @endif
            </div>
        @endforeach
    </div>
</div>
