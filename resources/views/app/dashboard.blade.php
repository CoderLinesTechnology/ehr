@php
    $d = $dashboard;
    $orgSlug = tenant()->organizationOrFail()->slug;
    $clientUrl = fn (string $id) => \Illuminate\Support\Facades\Route::has('app.clients.show') ? route('app.clients.show', ['organization' => $orgSlug, 'client' => $id]) : null;
    $appointmentUrl = fn (array $a) => \Illuminate\Support\Facades\Route::has('app.appointments.show')
        ? route('app.appointments.show', ['organization' => $orgSlug, 'appointment' => $a['id']])
        : $clientUrl($a['clientId']);
    $calendarUrl = \Illuminate\Support\Facades\Route::has('app.calendar.index') ? route('app.calendar.index', ['organization' => $orgSlug]) : null;
    $clientsUrl = \Illuminate\Support\Facades\Route::has('app.clients.index') ? route('app.clients.index', ['organization' => $orgSlug]) : null;
@endphp
<x-layouts.app title="Dashboard">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/dashboard.css') }}">
    @endpush

    <div class="dash">
        <header class="dash-greeting">
            <h1 class="dash-greeting__title">{{ $d['greeting'] }}, {{ $d['firstName'] }} <span class="dash-greeting__wave" aria-hidden="true">👋</span></h1>
            <p class="dash-greeting__sub">Here's a quick look at what's happening today.</p>
        </header>

        @if ($d['stats'] !== [])
            <div class="dash-stats">
                @foreach ($d['stats'] as $stat)
                    <x-ui.stat class="dash-stat" shape="circle" :label="$stat['label']" :value="number_format($stat['value'])" :icon="$stat['icon']" :trend="$stat['trend']" :hint="$stat['hint'] ?? null" :href="$stat['href']" />
                @endforeach
            </div>
        @endif

        <div class="dash-columns">
            <div class="dash-main">
                @if ($d['onboarding'] !== null)
                    <section class="dash-panel dash-onboarding" aria-labelledby="dash-onboarding-title">
                        <div class="dash-panel__head">
                            <h2 class="dash-panel__title" id="dash-onboarding-title">Finish setting up your practice</h2>
                            <span class="dash-panel__meta">{{ collect($d['onboarding'])->where('done', true)->count() }} of {{ count($d['onboarding']) }} done</span>
                        </div>
                        <ul class="checklist" role="list">
                            @foreach ($d['onboarding'] as $step)
                                <x-ui.checklist-item :done="$step['done']" :title="$step['title']" :description="$step['description']" :href="$step['done'] ? null : $step['url']" />
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($d['canSeeAppointments'])
                    <section class="dash-panel dash-upcoming" aria-labelledby="dash-upcoming-title">
                        <div class="dash-panel__head">
                            <h2 class="dash-panel__title" id="dash-upcoming-title">Upcoming Appointments</h2>
                            @if ($calendarUrl)<a class="dash-link" href="{{ $calendarUrl }}">View all</a>@endif
                        </div>
                        @if ($d['upcoming'] === [])
                            <p class="dash-empty">No upcoming appointments.</p>
                        @else
                            <ul class="dash-appts" role="list">
                                @foreach ($d['upcoming'] as $a)
                                    @php($url = $appointmentUrl($a))
                                    <li class="dash-appt">
                                        <x-ui.avatar :name="$a['name']" size="lg" class="dash-appt__avatar" :decorative="true" />
                                        <div class="dash-appt__who">
                                            <p class="dash-appt__name">{{ $a['name'] }}@if ($a['demo']) <x-ui.badge tone="demo">Demo</x-ui.badge>@endif</p>
                                            <p class="dash-appt__service">{{ $a['service'] }}</p>
                                        </div>
                                        <time class="dash-appt__when">{{ $a['when'] }}</time>
                                        <x-ui.badge :tone="$a['statusTone']" class="dash-appt__status">{{ $a['statusLabel'] }}</x-ui.badge>
                                        @if ($url)
                                            <a class="dash-appt__go" href="{{ $url }}" aria-label="Open appointment with {{ $a['name'] }}"><x-ui.icon name="chevron-right" :size="16" /></a>
                                        @else
                                            <span class="dash-appt__go" aria-hidden="true"></span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endif

                @if ($d['recentClients'] !== [])
                    <section class="dash-panel dash-recent" aria-labelledby="dash-recent-title">
                        <div class="dash-panel__head">
                            <h2 class="dash-panel__title dash-panel__title--sm" id="dash-recent-title">Recent Clients</h2>
                            @if ($clientsUrl)<a class="dash-link" href="{{ $clientsUrl }}">View all</a>@endif
                        </div>
                        <ul class="dash-clients" role="list">
                            @foreach ($d['recentClients'] as $c)
                                <li>
                                    <a class="dash-client" href="{{ $clientUrl($c['id']) }}">
                                        <x-ui.avatar :name="$c['name']" size="lg" class="dash-client__avatar" :decorative="true" />
                                        <span class="dash-client__text">
                                            <span class="dash-client__name">{{ $c['name'] }}</span>
                                            <span class="dash-client__status"><span class="dash-dot dash-dot--{{ $c['status']->value }}" aria-hidden="true"></span>{{ $c['status']->label() }}@if ($c['demo']) · Demo @endif</span>
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>

            <aside class="dash-rail">
                <section class="dash-hero" aria-labelledby="dash-hero-title">
                    <span class="dash-hero__icon" aria-hidden="true"><x-ui.icon name="hand-heart" :size="32" :stroke="1.5" /></span>
                    <div class="dash-hero__text">
                        <h2 class="dash-hero__title" id="dash-hero-title">You're making a difference</h2>
                        <p class="dash-hero__body">Every session, every conversation, contributes to better mental health.</p>
                        @if ($clientsUrl && $d['recentClients'] !== [])<a class="dash-hero__btn" href="{{ $clientsUrl }}">View All Clients</a>@endif
                    </div>
                </section>

                @if ($d['quickActions'] !== [])
                    <section class="dash-panel dash-actions" aria-labelledby="dash-actions-title">
                        <h2 class="dash-rail__title" id="dash-actions-title"><x-ui.icon name="zap" :size="18" /> Quick Actions</h2>
                        <ul class="dash-actions__list" role="list">
                            @foreach ($d['quickActions'] as $action)
                                <li><a class="dash-action" href="{{ $action['url'] }}"><x-ui.icon :name="$action['icon']" :size="18" class="dash-action__icon" /><span>{{ $action['label'] }}</span><x-ui.icon name="chevron-right" :size="16" class="dash-action__go" /></a></li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($d['canSeeAppointments'])
                    <section class="dash-panel dash-today" aria-labelledby="dash-today-title">
                        <h2 class="dash-rail__title" id="dash-today-title"><x-ui.icon name="calendar" :size="18" /> Today's Schedule</h2>
                        @if ($d['today'] === [])
                            <p class="dash-empty dash-empty--sm">Nothing scheduled today.</p>
                        @else
                            <ul class="dash-schedule" role="list">
                                @foreach ($d['today'] as $a)
                                    <li class="dash-schedule__row">
                                        <time class="dash-schedule__time">{{ $a['time'] }}</time>
                                        <span class="dash-schedule__name">{{ $a['name'] }}</span>
                                        <span class="dash-schedule__service">{{ $a['service'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endif
            </aside>
        </div>
    </div>
</x-layouts.app>
