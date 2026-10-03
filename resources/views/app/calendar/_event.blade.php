@php
    $place ??= null;
    $style = $place ? sprintf('--top:%spx;--h:%spx;--lane:%d;--lanes:%d', $place['top'], $place['height'], $place['lane'], $place['lanes']) : null;
    $telehealth = $event->modality === \App\Domain\Scheduling\Modality::Telehealth;
@endphp
<a href="{{ $event->programId !== null ? route('app.programs.sessions.show', ['program' => $event->programId, 'session' => $event->id]) : route('app.appointments.show', ['appointment' => $event->id]) }}" @class(['cal-event', 'cal-event--program' => $event->kind === 'program', 'cal-event--'.$event->family, 'is-closed' => $event->isClosed(), 'is-lane' => $place && $place['lanes'] > 1, 'is-noshow' => $event->status === \App\Domain\Scheduling\AppointmentStatus::NoShow]) @if ($style) style="{{ $style }}" @endif
   title="{{ fmt()->time($event->start, $event->zone) }} · {{ $event->clientName }} · {{ $event->serviceName }} · {{ $event->clinicianName }} · {{ $event->kind === 'program' ? 'Program session' : $event->status->badgeLabel() }}">
    <span class="cal-event__time">{{ fmt()->time($event->start, $event->zone) }}</span>
    <span class="cal-event__client">{{ $event->clientName }}</span>
    <span class="cal-event__service">{{ $event->serviceName }}</span>
    <span class="cal-event__loc"><x-ui.icon :name="$telehealth ? 'video' : 'map-pin'" :size="10" :stroke="1.5" /><span>{{ $event->locationLabel }}</span></span>
    @if ($event->isClosed() || $event->status === \App\Domain\Scheduling\AppointmentStatus::NoShow)<span class="sr-only">{{ $event->status->badgeLabel() }}</span>@endif
</a>
