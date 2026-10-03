@php
    use App\Domain\Programs\AttendanceStatus;
@endphp
<x-layouts.app :title="$session->title">
    @push('styles')
        @include('app.programs._head')
    @endpush

    <div class="pr pr--page">
        <x-ui.page-header class="pr-pagehead" :title="$session->title" :description="$program->name" icon="calendar">
            <x-slot:breadcrumbs><x-ui.breadcrumbs :items="[['label' => 'Programs', 'url' => route('app.programs.index')], ['label' => $program->name, 'url' => route('app.programs.show', ['program' => $program, 'tab' => 'schedule'])], ['label' => $session->title]]" /></x-slot:breadcrumbs>
            <x-slot:meta>
                <span class="pr-metaline">
                    <span><x-ui.icon name="calendar" :size="14" />{{ fmt()->localDate($session->starts_at, $session->timezone) }}, {{ fmt()->time($session->starts_at, $session->timezone) }} – {{ fmt()->time($session->ends_at, $session->timezone) }}</span>
                    <span><x-ui.icon name="map-pin" :size="14" />{{ $session->placeLabel() }}</span>
                    @if ($session->facilitator)<span><x-ui.icon name="user-round" :size="14" />{{ $session->facilitator->professionalName() }}</span>@endif
                    @if ($session->isCancelled())<x-ui.badge tone="neutral">Cancelled</x-ui.badge>@endif
                </span>
            </x-slot:meta>
            <x-slot:actions>
                @if ($canManage && ! $session->isCancelled() && $session->starts_at->isFuture())
                    <x-ui.confirm-form :action="route('app.programs.sessions.cancel', ['program' => $program, 'session' => $session])" title="Cancel this session?" message="It leaves the schedule and the calendar." confirm-label="Cancel session" button-label="Cancel session" button-variant="secondary" />
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        <section class="pr-panel" aria-labelledby="pr-attendance">
            <h2 id="pr-attendance" class="pr-panel__title">Attendance</h2>
            @if (! $canParticipants)
                <p class="pr-empty">Participants of this program are restricted.</p>
            @elseif ($sheet['rows']->isEmpty())
                <p class="pr-empty">No active participants.</p>
            @else
                @if ($sheet['truncated'])
                    <x-ui.alert tone="warning">This program has more than {{ \App\Domain\Programs\ProgramDetail::ATTENDANCE_ROWS }} active participants: only the first {{ \App\Domain\Programs\ProgramDetail::ATTENDANCE_ROWS }} (by name) are listed.</x-ui.alert>
                @endif
                <form method="POST" action="{{ route('app.programs.sessions.attendance', ['program' => $program, 'session' => $session]) }}" data-submit-once>
                    @csrf
                    <x-ui.table label="Attendance" :compact="true">
                        <thead><tr><th scope="col">Participant</th><th scope="col">Attendance</th></tr></thead>
                        <tbody>
                            @foreach ($sheet['rows'] as $enrollment)
                                @php $current = $sheet['recorded'][$enrollment->id] ?? null; @endphp
                                <tr>
                                    <td>{{ $enrollment->client->displayName() }} <small class="pr-muted">{{ $enrollment->client->formattedNumber() }}</small></td>
                                    <td>
                                        @if ($canRecord)
                                            <span class="pr-choices" role="radiogroup" aria-label="Attendance of {{ $enrollment->client->displayName() }}">
                                                @foreach (AttendanceStatus::cases() as $option)
                                                    <label><input type="radio" name="attendance[{{ $enrollment->id }}]" value="{{ $option->value }}" @checked($current === $option)><span>{{ $option->label() }}</span></label>
                                                @endforeach
                                            </span>
                                        @else
                                            @if ($current)<x-ui.badge :tone="$current->tone()">{{ $current->label() }}</x-ui.badge>@else<span class="pr-muted">Not recorded</span>@endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                    @if ($canRecord)
                        <div class="form-actions"><x-ui.button type="submit">Save attendance</x-ui.button></div>
                    @endif
                </form>
            @endif
        </section>
    </div>
</x-layouts.app>
