<li class="appt-list__item">
    <div class="appt-list__when">
        {{ fmt()->localDate($appointment->starts_at, $appointment->timezone) }}
        <span>{{ fmt()->time($appointment->starts_at, $appointment->timezone) }}</span>
    </div>
    <p class="appt-list__what">
        {{ $appointment->service?->name ?? 'Appointment' }}
        <span class="appt-list__sub">
            {{ $appointment->clinician?->displayName() }}@if ($appointment->modality?->value === 'telehealth') · Online @elseif ($appointment->location) · {{ $appointment->location->name }}@endif
        </span>
    </p>
    <x-ui.badge :tone="$tone[$appointment->status->value] ?? 'neutral'">{{ $appointment->status->label() }}</x-ui.badge>
</li>
