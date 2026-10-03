@php
    $tab = $list->tab;
    $headings = ['upcoming' => 'Upcoming Telehealth Sessions', 'past' => 'Past Telehealth Sessions', 'all' => 'All Telehealth Sessions'];
    $tabs = ['upcoming' => 'Upcoming', 'past' => 'Past', 'all' => 'All'];
    $actions = [
        ['key' => 'start', 'icon' => 'video', 'title' => 'Start a Session', 'lines' => ['Begin an ad-hoc video session', 'with a client.']],
        ['key' => 'calendar', 'icon' => 'calendar-days', 'title' => 'View Calendar', 'lines' => ['See your scheduled', 'telehealth sessions.']],
        ['key' => 'check', 'icon' => 'link', 'title' => 'Test Connection', 'lines' => ['Check your camera, mic,', 'and internet before a session.']],
        ['key' => 'settings', 'icon' => 'settings', 'title' => 'Telehealth Settings', 'lines' => ['Manage provider, preferences,', 'and session options.']],
    ];
    $format = app(\App\Support\Formatter::class);
@endphp
<x-layouts.app title="Telehealth">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/telehealth.css') }}?v={{ filemtime(public_path('css/screens/telehealth.css')) }}">
    @endpush

    <div class="tele">
        <div class="tele-main">
            <x-ui.page-header class="tele-header" title="Telehealth" description="Join sessions, manage your telehealth appointments, and connect with your clients." icon="video" />

            <ul class="tele-actions" aria-label="Telehealth shortcuts">
                @foreach ($actions as $action)
                    @if ($links[$action['key']] !== null)
                        <li>
                            <a href="{{ $links[$action['key']] }}" class="tele-action">
                                <span class="tele-action__tile"><x-ui.icon :name="$action['icon']" :size="20" :stroke="2.3" /></span>
                                <span class="tele-action__title">{{ $action['title'] }}</span>
                                <span class="tele-action__text">@foreach ($action['lines'] as $line)<span>{{ $line }}</span>{{ $loop->last ? '' : ' ' }}@endforeach</span>
                                <x-ui.icon name="arrow-right" :size="14" :stroke="2.2" class="tele-action__go" />
                            </a>
                        </li>
                    @endif
                @endforeach
            </ul>

            <nav class="tele-tabs" aria-label="Session list">
                @foreach ($tabs as $key => $label)
                    <a href="{{ route('app.telehealth.index', ['tab' => $key]) }}" class="tele-tab{{ $tab === $key ? ' is-active' : '' }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }}@if ($key === 'upcoming' && $tab === 'upcoming') ({{ $list->counts['upcoming'] }})@else<span class="sr-only"> ({{ $list->counts[$key] }})</span>@endif</a>
                @endforeach
            </nav>

            <section class="tele-list" aria-labelledby="tele-list-title">
                <h2 class="tele-list__title" id="tele-list-title">{{ $headings[$tab] }}</h2>

                @if ($list->rows === [])
                    <p class="tele-list__empty">
                        @if ($tab === 'upcoming') No upcoming telehealth sessions. Book an appointment with the telehealth option and it will appear here.
                        @else No telehealth sessions to show here yet. @endif
                    </p>
                @else
                    <ul class="tele-rows">
                        @foreach ($list->rows as $row)
                            <li class="tele-row">
                                <div class="tele-row__who">
                                    @include('app.telehealth.partials-avatar', ['name' => $row->clientName, 'number' => $row->clientNumber, 'size' => 'lg', 'class' => 'tele-row__avatar'])
                                    <div class="tele-row__text">
                                        <span class="tele-row__name">{{ $row->clientName }}</span>
                                        <span class="tele-row__sub">{{ $row->clientNumber }}</span>
                                    </div>
                                </div>
                                <div class="tele-row__what">
                                    <span class="tele-row__service">{{ $row->serviceName }}</span>
                                    <span class="tele-row__sub">With {{ $row->clinicianName }}</span>
                                </div>
                                <div class="tele-row__when">
                                    <span class="tele-row__line"><x-ui.icon name="calendar" :size="12" :stroke="2" />{{ $format->localDate($row->startsAt, $row->timezone) }}</span>
                                    <span class="tele-row__line"><x-ui.icon name="clock" :size="12" :stroke="2" />{{ $format->time($row->startsAt, $row->timezone) }} – {{ $format->time($row->endsAt, $row->timezone) }}</span>
                                </div>
                                <span class="tele-row__mode"><x-ui.icon name="video" :size="14" :stroke="2" />Video</span>
                                <span class="tele-row__status"><span class="tele-pill tele-pill--{{ $row->status->tone() }}">{{ $row->status->label() }}</span></span>
                                <span class="tele-row__action">
                                    @if ($row->joinable)
                                        <a href="{{ route('app.telehealth.join', ['session' => $row->id]) }}" class="tele-btn tele-btn--primary" aria-label="Join session with {{ $row->clientName }}">Join</a>
                                    @else
                                        <a href="{{ route('app.telehealth.show', ['session' => $row->id]) }}" class="tele-btn tele-btn--outline" aria-label="View session with {{ $row->clientName }}">View</a>
                                    @endif
                                </span>
                                <x-ui.dropdown class="tele-row__menu" icon="ellipsis-vertical" :label="'Actions for the session with '.$row->clientName" align="right" size="sm">
                                    <x-ui.dropdown-item :href="route('app.telehealth.show', ['session' => $row->id])" icon="eye">View session</x-ui.dropdown-item>
                                    @if ($row->joinable)
                                        <x-ui.dropdown-item :href="route('app.telehealth.join', ['session' => $row->id])" icon="video">Join session</x-ui.dropdown-item>
                                    @endif
                                </x-ui.dropdown>
                            </li>
                        @endforeach
                    </ul>
                    <footer class="tele-list__foot">
                        <p>Showing {{ $list->from() }}–{{ $list->to() }} of {{ $list->total() }} {{ \Illuminate\Support\Str::plural('session', $list->total()) }}</p>
                        @if ($list->page > 1 || $list->hasMore())
                            <nav class="tele-pager" aria-label="Pages">
                                @if ($list->page > 1)<a href="{{ route('app.telehealth.index', ['tab' => $tab, 'page' => $list->page - 1]) }}" rel="prev">Previous</a>@endif
                                @if ($list->hasMore())<a href="{{ route('app.telehealth.index', ['tab' => $tab, 'page' => $list->page + 1]) }}" rel="next">Next</a>@endif
                            </nav>
                        @endif
                    </footer>
                @endif
            </section>
        </div>

        <aside class="tele-rail" aria-label="Telehealth">
            <section class="tele-ready">
                <span class="tele-ready__tile"><x-ui.icon name="video" :size="32" :stroke="2.2" /></span>
                <h2 class="tele-ready__title">Ready for your session?</h2>
                <p class="tele-ready__text">Join your telehealth session with one click. Make sure your camera and microphone are working.</p>
                @if ($nextUrl !== null)
                    <a href="{{ $nextUrl }}" class="tele-ready__btn"><x-ui.icon name="video" :size="18" :stroke="2" />Join Session</a>
                @else
                    <a href="{{ $links['check'] }}" class="tele-ready__btn"><x-ui.icon name="video" :size="18" :stroke="2" />Test Connection</a>
                @endif
            </section>

            <section class="tele-links" aria-labelledby="tele-links-title">
                <h2 class="tele-links__title" id="tele-links-title"><x-ui.icon name="link" :size="20" :stroke="2" />Quick Links</h2>
                <ul>
                    @foreach ([['appointments', 'My Appointments'], ['instructions', 'Telehealth Instructions'], ['support', 'Help & Support'], ['directory', 'Provider Directory']] as [$key, $label])
                        @if ($links[$key] !== null)
                            <li><a href="{{ $links[$key] }}"><span>{{ $label }}</span><x-ui.icon name="chevron-right" :size="14" :stroke="2" /></a></li>
                        @endif
                    @endforeach
                </ul>
            </section>

            <section class="tele-tips" aria-labelledby="tele-tips-title">
                <h2 class="tele-tips__title" id="tele-tips-title"><x-ui.icon name="lightbulb" :size="18" :stroke="2" />Telehealth Tips</h2>
                <ul>
                    @foreach (['Use a stable internet connection.', 'Find a quiet, private space.', 'Test your camera and microphone ahead of time.', 'Keep your device charged.'] as $tip)
                        <li><x-ui.icon name="check" :size="14" :stroke="2.4" /><span>{{ $tip }}</span></li>
                    @endforeach
                </ul>
            </section>
        </aside>
    </div>
</x-layouts.app>
