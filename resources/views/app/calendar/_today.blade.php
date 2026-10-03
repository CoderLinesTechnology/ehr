@php
    $twelve = str_contains(fmt()->timeFormat(), 'A');
    $tableTime = fn ($e) => $twelve ? $e->start->format('h:i A') : fmt()->time($e->start, $e->zone);
@endphp
<section class="card cal-today" aria-labelledby="cal-today-title">
    <header class="cal-today__head">
        <x-ui.icon name="calendar" :size="18" />
        <h2 id="cal-today-title">Today's Appointments</h2>
        <a href="{{ route('app.calendar.index', $filters->query(view: 'day', date: $today)) }}" class="cal-today__all">View all</a>
    </header>
    @if ($todayAppointments->isEmpty())
        <p class="cal-today__empty">Nothing booked for today{{ $filters->isNarrowed() ? ' with these filters' : '' }}.</p>
    @else
        <div class="cal-today__scroll table-wrap" data-table-scroll tabindex="0" role="region" aria-label="Today's appointments">
            <table class="cal-table">
                <thead>
                    <tr>
                        <th scope="col">Time</th><th scope="col">Client</th><th scope="col">Service</th><th scope="col">Clinician</th>
                        <th scope="col">Location</th><th scope="col">Modality</th><th scope="col">Status</th><th scope="col" class="cal-table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($todayAppointments as $e)
                        <tr>
                            <td class="cal-table__time">{{ $tableTime($e) }}</td>
                            <td><span class="cal-person"><x-ui.avatar :name="$e->clientName" size="xs" :decorative="true" /><span>{{ $e->clientName }}</span></span></td>
                            <td class="cal-table__service">{{ $e->serviceName }}</td>
                            <td><span class="cal-person cal-person--clinician"><x-ui.avatar :name="$e->clinicianName" size="xs" :decorative="true" /><span>{{ $e->clinicianName }}</span></span></td>
                            <td><span class="cal-loc"><x-ui.icon :name="$e->modality === \App\Domain\Scheduling\Modality::Telehealth ? 'video' : 'map-pin'" :size="10" :stroke="1.5" /><span>{{ $e->locationLabel }}</span></span></td>
                            <td class="cal-table__modality">{{ $e->modality === \App\Domain\Scheduling\Modality::Telehealth ? 'Telehealth' : 'In-person' }}</td>
                            <td><x-ui.badge :tone="$e->status->badgeTone()" class="cal-pill">{{ $e->status->badgeLabel() }}</x-ui.badge></td>
                            <td class="cal-table__actions"><a href="{{ route('app.appointments.show', ['appointment' => $e->id]) }}" class="cal-dots" aria-label="Open appointment with {{ $e->clientName }}"><x-ui.icon name="ellipsis" :size="16" :stroke="2" /></a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
