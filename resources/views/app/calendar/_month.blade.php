@php
    $weekdays = array_slice($monthDays, 0, 7);
@endphp
<div class="cal-scroll" data-cal-scroll>
    <div class="cal-month">
        @foreach ($weekdays as $d)<div class="cal-month__dow">{{ $d['date']->format('D') }}</div>@endforeach
        @foreach ($monthDays as $d)
            <div @class(['cal-month__day', 'is-outside' => ! $d['inMonth'], 'is-today' => $d['isToday']])>
                <a href="{{ route('app.calendar.index', $filters->query(view: 'day', date: $d['date']->format('Y-m-d'))) }}" class="cal-month__num" aria-label="{{ $d['date']->format('l, F j') }}">{{ $d['date']->day }}</a>
                @foreach ($d['events'] as $event)
                    <a href="{{ $event->programId !== null ? route('app.programs.sessions.show', ['program' => $event->programId, 'session' => $event->id]) : route('app.appointments.show', ['appointment' => $event->id]) }}" @class(['cal-chip', 'cal-event--'.$event->family, 'is-closed' => $event->isClosed()]) title="{{ $event->clientName }} · {{ $event->serviceName }} · {{ $event->clinicianName }}">
                        <span class="cal-chip__time">{{ fmt()->time($event->start, $event->zone) }}</span> <span class="cal-chip__name">{{ $event->clientName }}</span>
                    </a>
                @endforeach
                @if ($d['more'] > 0)
                    <a href="{{ route('app.calendar.index', $filters->query(view: 'day', date: $d['date']->format('Y-m-d'))) }}" class="cal-month__more">+{{ $d['more'] }} more</a>
                @endif
            </div>
        @endforeach
    </div>
</div>
