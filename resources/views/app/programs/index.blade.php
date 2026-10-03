@php
    use App\Domain\Programs\ProgramStatus;

    $tabs = [['All Programs', null, $counts['all']], ['Active', ProgramStatus::Active, $counts['active']], ['Upcoming', ProgramStatus::Upcoming, $counts['upcoming']],
        ['Completed', ProgramStatus::Completed, $counts['completed']], ['On Hold', ProgramStatus::OnHold, $counts['on_hold']], ['Archived', ProgramStatus::Archived, $counts['archived']]];
    $dots = ['blue', 'teal', 'red'];
    $admitUrl = $canEnroll ? route('app.programs.admit') : null;
    $calendarUrl = $hasCalendar ? route('app.calendar.index', ['program' => 'all']) : null;
@endphp
<x-layouts.app title="Programs">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/programs.css') }}?v={{ filemtime(public_path('css/screens/programs.css')) }}">
    @endpush

    <div class="pr">
        <div class="pr-main">
            <header class="pr-head">
                <span class="pr-head__tile" aria-hidden="true"><x-ui.icon name="users-round" :size="28" /></span>
                <div class="pr-head__text">
                    <h1>Programs</h1>
                    <p>Manage your programs, participants, and progress.</p>
                </div>
                @if ($canCreate)
                    <a href="{{ route('app.programs.create') }}" class="pr-create"><x-ui.icon name="plus" :size="18" /><span>Create Program</span></a>
                @endif
            </header>

            <div class="pr-bar">
                <nav class="pr-tabs" aria-label="Program status">
                    @foreach ($tabs as [$label, $status, $count])
                        @php $active = ($filters->status === $status); @endphp
                        <a href="{{ route('app.programs.index', $filters->query(['status' => $status?->value])) }}" class="pr-tab{{ $active ? ' is-active' : '' }}" @if ($active) aria-current="page" @endif>{{ $label }}<span class="sr-only"> ({{ $count }})</span></a>
                    @endforeach
                </nav>
                <form method="GET" action="{{ route('app.programs.index') }}" role="search" class="pr-search">
                    @if ($filters->status !== null)<input type="hidden" name="status" value="{{ $filters->status->value }}">@endif
                    <x-ui.icon name="search" :size="16" class="pr-search__icon" />
                    <label for="program-search" class="sr-only">Search programs</label>
                    <input type="search" id="program-search" name="q" value="{{ $filters->q }}" maxlength="100" placeholder="Search programs..." autocomplete="off" enterkeyhint="search">
                </form>
                <details class="dropdown dropdown--right pr-filters">
                    <summary class="pr-filters__btn"><x-ui.icon name="funnel" :size="17" /><span>Filters</span></summary>
                    <div class="dropdown__menu pr-filters__menu">
                        <form method="GET" action="{{ route('app.programs.index') }}" class="pr-filters__form">
                            <x-ui.field label="Status" name="status"><x-ui.select name="status" :value="$filters->status?->value" placeholder="All statuses" :options="\App\Domain\Programs\ProgramStatus::options()" /></x-ui.field>
                            <x-ui.field label="Search" name="q"><x-ui.input name="q" :value="$filters->q" maxlength="100" /></x-ui.field>
                            <div class="pr-filters__actions">
                                <x-ui.button variant="secondary" size="sm" :href="route('app.programs.index')">Reset</x-ui.button>
                                <x-ui.button type="submit" size="sm">Apply</x-ui.button>
                            </div>
                        </form>
                    </div>
                </details>
            </div>

            @if ($cards->isNotEmpty())
                <div class="pr-grid">
                    @foreach ($cards as $program)
                        @php
                            $count = $program->participant_count;
                            $level = $program->primaryLevel;
                        @endphp
                        <article class="pr-card">
                            <div class="pr-card__head">
                                <span class="pr-tile pr-tile--{{ $program->color->value }}" aria-hidden="true"><x-ui.icon :name="$program->icon" :size="32" :stroke="2" /></span>
                                <div class="pr-card__titles">
                                    <h2 class="pr-card__title"><a href="{{ route('app.programs.show', ['program' => $program]) }}">{{ $program->name }}</a></h2>
                                    @if ($level)<span class="pr-level">{{ $level->name }}</span>@endif
                                </div>
                                <span class="pr-status pr-status--{{ $program->status->value }}">{{ $program->status->label() }}</span>
                            </div>
                            <ul class="pr-meta">
                                <li class="pr-meta__people"><x-ui.icon name="users" :size="16" />@if ($count === null)<span>Restricted</span>@else<span>{{ $count }} {{ $count === 1 ? 'Participant' : 'Participants' }}</span>@endif</li>
                                <li class="pr-meta__place"><x-ui.icon name="map-pin" :size="16" /><span>{{ $program->placeLabel() }}</span></li>
                                <li class="pr-meta__date"><x-ui.icon name="calendar" :size="16" /><span>{{ $program->dateRange() }}</span></li>
                            </ul>
                            @if (filled($program->description))<p class="pr-card__desc">{{ $program->description }}</p>@endif
                            <div class="pr-card__foot">
                                <a href="{{ route('app.programs.show', ['program' => $program]) }}" class="pr-view">View Details</a>
                                <x-ui.dropdown align="right" class="pr-kebab" icon="ellipsis" :label="'Actions for '.$program->name">
                                    <x-ui.dropdown-item :href="route('app.programs.show', ['program' => $program])" icon="eye">View details</x-ui.dropdown-item>
                                    @can('update', $program)<x-ui.dropdown-item :href="route('app.programs.edit', ['program' => $program])" icon="pencil">Edit program</x-ui.dropdown-item>@endcan
                                    @can('admit', $program)
                                        @if (in_array($program->status, [ProgramStatus::Upcoming, ProgramStatus::Active], true))
                                            <x-ui.dropdown-item :href="route('app.programs.admit', ['program' => $program])" icon="user-plus">Add participant</x-ui.dropdown-item>
                                        @endif
                                    @endcan
                                    @if ($canManage)@can('manage', $program)<x-ui.dropdown-item :href="route('app.programs.show', ['program' => $program, 'tab' => 'levels'])" icon="layers">Levels of care</x-ui.dropdown-item>@endcan @endif
                                </x-ui.dropdown>
                            </div>
                        </article>
                    @endforeach
                </div>
                @if ($cards->hasPages())<div class="pr-pager">{{ $cards->links() }}</div>@endif
            @else
                <x-ui.empty-state icon="users-round"
                    :title="($filters->q !== '' || $filters->status !== null) ? 'No programs match' : 'No programs yet'"
                    :description="($filters->q !== '' || $filters->status !== null) ? 'Try another status or different words.' : ($canCreate ? 'Create your first program to start admitting participants.' : 'Your organization has not set up any programs yet.')">
                    <x-slot:actions>
                        @if ($filters->q !== '' || $filters->status !== null)
                            <x-ui.button variant="secondary" :href="route('app.programs.index')">Show all programs</x-ui.button>
                        @elseif ($canCreate)
                            <x-ui.button icon="plus" :href="route('app.programs.create')">Create Program</x-ui.button>
                        @endif
                    </x-slot:actions>
                </x-ui.empty-state>
            @endif
        </div>

        <aside class="pr-rail" aria-label="Program overview and shortcuts">
            <section class="pr-box pr-overview" aria-labelledby="pr-overview-title">
                <h2 id="pr-overview-title">Program Overview</h2>
                <div class="pr-stats">
                    @foreach ([['users-round', 'blue', $stats['programs'], 'Total Programs'], ['user-round', 'green', $stats['participants'], 'Total Participants'], ['calendar', 'sky', $stats['upcoming'], 'Upcoming Starts'], ['chart-line', 'purple', $stats['completed'], 'Completed']] as [$icon, $tone, $value, $label])
                        <div class="pr-stat">
                            <span class="pr-stat__tile pr-stat__tile--{{ $tone }}" aria-hidden="true"><x-ui.icon :name="$icon" :size="20" /></span>
                            <span class="pr-stat__value">{{ $value }}</span>
                            <span class="pr-stat__label">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="pr-box pr-quick" aria-labelledby="pr-quick-title">
                <h2 id="pr-quick-title">Quick Actions</h2>
                <ul>
                    @if ($admitUrl)<li><a href="{{ $admitUrl }}"><x-ui.icon name="users" :size="22" /><span>Add Participant</span><x-ui.icon name="chevron-right" :size="15" /></a></li>@endif
                    @if ($calendarUrl)<li><a href="{{ $calendarUrl }}"><x-ui.icon name="calendar" :size="22" /><span>View Program Calendar</span><x-ui.icon name="chevron-right" :size="15" /></a></li>@endif
                    @if ($hasReports)<li><a href="{{ route('app.reports.index') }}"><x-ui.icon name="file-text" :size="22" /><span>Generate Report</span><x-ui.icon name="chevron-right" :size="15" /></a></li>@endif
                    @if ($canManage && ($levelsTarget = $cards->first(fn ($p) => Gate::allows('manage', $p))))<li><a href="{{ route('app.programs.show', ['program' => $levelsTarget, 'tab' => 'levels']) }}"><x-ui.icon name="user-round-cog" :size="22" /><span>Manage Levels of Care</span><x-ui.icon name="chevron-right" :size="15" /></a></li>@endif
                </ul>
            </section>

            <section class="pr-box pr-schedule" aria-labelledby="pr-schedule-title">
                <header>
                    <h2 id="pr-schedule-title">Upcoming Program Schedule</h2>
                    @if ($calendarUrl)<a href="{{ $calendarUrl }}">View All</a>@endif
                </header>
                @if ($schedule !== [])
                    <ul>
                        @foreach ($schedule as $i => $item)
                            <li class="pr-session">
                                <span class="pr-session__dot pr-session__dot--{{ $dots[$i % 3] }}" aria-hidden="true"></span>
                                <div>
                                    <p class="pr-session__title">{{ $item['title'] }}</p>
                                    <p class="pr-session__when">{{ fmt()->date($item['start']) }} • {{ fmt()->time($item['start'], $item['zone']) }} – {{ fmt()->time($item['end'], $item['zone']) }}</p>
                                    <p class="pr-session__place"><x-ui.icon name="map-pin" :size="12" /><span>{{ $item['place'] }}</span></p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="pr-schedule__empty">No sessions are scheduled yet.</p>
                @endif
            </section>
        </aside>
    </div>
</x-layouts.app>
