@php
    use App\Domain\Programs\ProgramStatus;
    use App\Domain\Programs\StaffRole;

    $open = $program->status->isOpen();
    $tabLinks = collect([
        ['overview', 'Overview'], ['participants', 'Participants'], ['staff', 'Staff'],
        ...($levelsOn ? [['levels', 'Levels of care']] : []), ...($sessionsOn ? [['schedule', 'Schedule']] : []),
    ])->map(fn ($t) => ['label' => $t[1], 'url' => route('app.programs.show', ['program' => $program, 'tab' => $t[0]]), 'active' => $tab === $t[0]])->all();
@endphp
<x-layouts.app :title="$program->name">
    @push('styles')
        @include('app.programs._head')
    @endpush

    <div class="pr pr--page">
        <x-ui.page-header class="pr-pagehead" :title="$program->name" :description="$program->description" :icon="$program->icon" icon-tone="blue">
            <x-slot:breadcrumbs><x-ui.breadcrumbs :items="[['label' => 'Programs', 'url' => route('app.programs.index')], ['label' => $program->name]]" /></x-slot:breadcrumbs>
            <x-slot:meta>
                <span class="pr-metaline">
                    <span class="pr-status pr-status--{{ $program->status->value }}">{{ $program->status->label() }}</span>
                    @if ($program->is_sud_program)<x-ui.badge tone="danger">42 CFR Part 2</x-ui.badge>@endif
                    <span><x-ui.icon name="map-pin" :size="14" />{{ $program->placeLabel() }}</span>
                    <span><x-ui.icon name="calendar" :size="14" />{{ $program->dateRange() }}</span>
                </span>
            </x-slot:meta>
            <x-slot:actions>
                @if ($canAdmit && in_array($program->status, [ProgramStatus::Upcoming, ProgramStatus::Active], true))
                    <x-ui.button icon="user-plus" :href="route('app.programs.admit', ['program' => $program])">Add participant</x-ui.button>
                @endif
                @if ($canUpdate)<x-ui.button variant="secondary" icon="pencil" :href="route('app.programs.edit', ['program' => $program])">Edit</x-ui.button>@endif
                @if ($canManage && $transitions !== [])
                    <x-ui.dropdown label="Status" align="right" icon="refresh-cw" :show-label="true">
                        @foreach ($transitions as $next)
                            <form method="POST" action="{{ route('app.programs.status', ['program' => $program]) }}" class="dropdown__form" data-submit-once>
                                @csrf
                                <input type="hidden" name="status" value="{{ $next->value }}">
                                <button type="submit" class="dropdown__item"><x-ui.icon :name="$next === ProgramStatus::Archived ? 'archive' : 'circle-check'" :size="16" /><span>{{ $next->action() }}</span></button>
                            </form>
                        @endforeach
                    </x-ui.dropdown>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.tabs :tabs="$tabLinks" variant="pills" label="Program sections" />

        @if ($tab === 'overview')
            <div class="pr-stats pr-stats--row">
                @foreach ([['users-round', 'blue', $canParticipants ? $summary['participants'] : 'Restricted', 'Participants'], ['layers', 'purple', $summary['levels'], 'Levels of care'], ['user-round-cog', 'green', $summary['staff'], 'Staff'], ['calendar', 'sky', $summary['sessions'], 'Upcoming sessions']] as [$icon, $tone, $value, $label])
                    <div class="pr-stat">
                        <span class="pr-stat__tile pr-stat__tile--{{ $tone }}" aria-hidden="true"><x-ui.icon :name="$icon" :size="20" /></span>
                        <span class="pr-stat__value">{{ $value }}</span>
                        <span class="pr-stat__label">{{ $label }}</span>
                    </div>
                @endforeach
            </div>
            <section class="pr-panel" aria-labelledby="pr-next">
                <h2 id="pr-next" class="pr-panel__title">Next session</h2>
                @if ($summary['next'])
                    @php $next = $summary['next']; @endphp
                    <p><strong>{{ $next->title }}</strong></p>
                    <p class="pr-muted">{{ fmt()->localDate($next->starts_at, $next->timezone) }} • {{ fmt()->time($next->starts_at, $next->timezone) }} – {{ fmt()->time($next->ends_at, $next->timezone) }} • {{ $next->placeLabel() }}</p>
                @else
                    <p class="pr-empty">No session is scheduled.</p>
                @endif
            </section>
            @if (! $canParticipants)
                <x-ui.alert tone="info" title="Participants are restricted">Participants, attendance and history of this program are visible only to people who may view substance-use program records (42 CFR Part 2).</x-ui.alert>
            @endif
        @elseif ($tab === 'participants')
            @if (! $canParticipants)
                <x-ui.alert tone="info" title="Participants are restricted">This program’s participants are visible only to people who may view substance-use program records (42 CFR Part 2).</x-ui.alert>
            @else
                <section class="pr-panel" aria-label="Participants">
                    <form method="GET" action="{{ route('app.programs.show', ['program' => $program]) }}" role="search" class="pr-inline-search">
                        <input type="hidden" name="tab" value="participants">
                        <label for="participant-search" class="sr-only">Search participants</label>
                        <x-ui.input id="participant-search" name="q" :value="$q" maxlength="100" placeholder="Search participants" />
                        <x-ui.select name="show" :value="$show" :options="['open' => 'Enrolled', 'ended' => 'Ended', 'all' => 'All']" aria-label="Show" />
                        <x-ui.button type="submit" variant="secondary">Apply</x-ui.button>
                    </form>
                    @if ($participants->isEmpty())
                        <p class="pr-empty">No participants to show.</p>
                    @else
                        <x-ui.table label="Participants" :compact="true">
                            <thead><tr><th scope="col">Client</th><th scope="col">Level of care</th><th scope="col">Status</th><th scope="col">Admitted</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                            <tbody>
                                @foreach ($participants as $enrollment)
                                    <tr>
                                        <td>{{ $enrollment->client->displayName() }} <small class="pr-muted">{{ $enrollment->client->formattedNumber() }}</small>@if ($enrollment->record_environment->value === 'demo') <x-ui.badge tone="demo" />@endif</td>
                                        <td>{{ $enrollment->level?->name ?? '—' }}</td>
                                        <td><x-ui.badge :tone="$enrollment->status->tone()">{{ $enrollment->status->label() }}</x-ui.badge></td>
                                        <td>{{ fmt()->localDate($enrollment->admitted_at) }}</td>
                                        <td class="pr-actions"><a href="{{ route('app.programs.enrollments.show', ['program' => $program, 'enrollment' => $enrollment]) }}">Open<span class="sr-only"> {{ $enrollment->client->displayName() }}</span></a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </x-ui.table>
                        @if ($participants->hasPages())<div class="pr-pager">{{ $participants->links() }}</div>@endif
                    @endif
                </section>
            @endif
        @elseif ($tab === 'staff')
            <section class="pr-panel" aria-label="Program staff">
                @if ($staff->isEmpty())
                    <p class="pr-empty">No staff are assigned yet.</p>
                @else
                    <x-ui.table label="Program staff" :compact="true">
                        <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                        <tbody>
                            @foreach ($staff as $row)
                                <tr>
                                    <td>{{ $row->membership->professionalName() }}</td>
                                    <td>{{ $row->role->label() }}</td>
                                    <td class="pr-actions">
                                        @if ($canManage)
                                            <form method="POST" action="{{ route('app.programs.staff.destroy', ['program' => $program, 'staff' => $row]) }}" data-submit-once>@csrf @method('DELETE')<button type="submit" class="pr-linkbtn">Remove<span class="sr-only"> {{ $row->membership->displayName() }}</span></button></form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                @endif
                @if ($canManage)
                    <form method="POST" action="{{ route('app.programs.staff.store', ['program' => $program]) }}" class="pr-form pr-form--row" data-submit-once novalidate>
                        @csrf
                        <x-ui.field label="Team member" name="membership_id" :required="true"><x-ui.select name="membership_id" :options="$members" placeholder="Choose" required /></x-ui.field>
                        <x-ui.field label="Role in program" name="role" :required="true"><x-ui.select name="role" :options="StaffRole::options()" value="clinician" required /></x-ui.field>
                        <x-ui.button type="submit">Add to staff</x-ui.button>
                    </form>
                @endif
            </section>
        @elseif ($tab === 'levels')
            <section class="pr-panel" aria-label="Levels of care">
                @if ($levels->isEmpty())
                    <p class="pr-empty">No levels of care yet.</p>
                @endif
                <ul class="pr-levels">
                    @foreach ($levels as $level)
                        <li>
                            <details class="pr-level-row">
                                <summary>
                                    <span class="pr-level-row__name">{{ $level->name }}</span>
                                    @unless ($level->is_active)<x-ui.badge tone="neutral">Off</x-ui.badge>@endunless
                                    @if ($canParticipants)<span class="pr-muted">{{ $levelCounts[$level->id] ?? 0 }} enrolled</span>@endif
                                </summary>
                                @if ($level->description)<p class="pr-muted">{{ $level->description }}</p>@endif
                                @if ($level->eligibility)<p class="pr-muted"><strong>Eligibility:</strong> {{ $level->eligibility }}</p>@endif
                                @if ($canManage)
                                    <form method="POST" action="{{ route('app.programs.levels.update', ['program' => $program, 'level' => $level]) }}" class="pr-form" data-submit-once novalidate>
                                        @csrf @method('PUT')
                                        @include('app.programs._level-fields', ['level' => $level])
                                        <x-ui.button type="submit" size="sm">Save level</x-ui.button>
                                    </form>
                                @endif
                            </details>
                        </li>
                    @endforeach
                </ul>
                @if ($canManage)
                    <h2 class="pr-panel__title">Add a level of care</h2>
                    <form method="POST" action="{{ route('app.programs.levels.store', ['program' => $program]) }}" class="pr-form" data-submit-once novalidate>
                        @csrf
                        @include('app.programs._level-fields', ['level' => null])
                        <x-ui.button type="submit">Add level</x-ui.button>
                    </form>
                @endif
            </section>
        @elseif ($tab === 'schedule')
            <section class="pr-panel" aria-label="Program schedule">
                @if ($sessions->isEmpty())
                    <p class="pr-empty">No sessions yet.</p>
                @else
                    <x-ui.table label="Sessions" :compact="true">
                        <thead><tr><th scope="col">Session</th><th scope="col">When</th><th scope="col">Where</th><th scope="col">Facilitator</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                        <tbody>
                            @foreach ($sessions as $session)
                                <tr>
                                    <td>{{ $session->title }}@if ($session->isCancelled()) <x-ui.badge tone="neutral">Cancelled</x-ui.badge>@endif</td>
                                    <td>{{ fmt()->localDate($session->starts_at, $session->timezone) }}, {{ fmt()->time($session->starts_at, $session->timezone) }} – {{ fmt()->time($session->ends_at, $session->timezone) }}</td>
                                    <td>{{ $session->placeLabel() }}</td>
                                    <td>{{ $session->facilitator?->professionalName() ?? '—' }}</td>
                                    <td class="pr-actions">
                                        <a href="{{ route('app.programs.sessions.show', ['program' => $program, 'session' => $session]) }}">Open<span class="sr-only"> {{ $session->title }}</span></a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                @endif
                @if ($canManage && $open)
                    <h2 class="pr-panel__title">Add a session</h2>
                    <form method="POST" action="{{ route('app.programs.sessions.store', ['program' => $program]) }}" class="pr-form" data-submit-once novalidate>
                        @csrf
                        <div class="pr-form__grid">
                            <x-ui.field class="pr-form__full" label="Title" name="title" :required="true" help="Shown on the calendar to everyone who can see programs. Do not put client names in it."><x-ui.input name="title" maxlength="120" placeholder="Group Therapy Session" required /></x-ui.field>
                            <x-ui.field label="Date" name="date" :required="true"><x-ui.input type="date" name="date" required /></x-ui.field>
                            <x-ui.field label="Where" name="place" :optional="true"><x-ui.select name="place" placeholder="No location" :options="['online' => 'Online'] + $locations" /></x-ui.field>
                            <x-ui.field label="Starts" name="start_time" :required="true"><x-ui.input type="time" name="start_time" required /></x-ui.field>
                            <x-ui.field label="Ends" name="end_time" :required="true"><x-ui.input type="time" name="end_time" required /></x-ui.field>
                            <x-ui.field class="pr-form__full" label="Facilitator" name="facilitator_membership_id" :optional="true"><x-ui.select name="facilitator_membership_id" placeholder="None" :options="$members" /></x-ui.field>
                        </div>
                        <x-ui.button type="submit">Add session</x-ui.button>
                    </form>
                @endif
            </section>
        @endif
    </div>
</x-layouts.app>
