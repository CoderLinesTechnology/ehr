@php
    use App\Domain\Resources\ResourceType;
    use App\Domain\Resources\ResourceStatus;

    $counts = $page['counts'];
    $listing = $filters->isListing();
    $latest = $page['latest'];
    $heading = match (true) {
        $filters->q !== '' => 'Search results',
        $filters->status !== null => $filters->status->label().' resources',
        $filters->all => 'All Resources',
        default => 'Latest Resources',
    };
    $hasRows = $latest instanceof \Illuminate\Contracts\Pagination\Paginator ? $latest->isNotEmpty() : $latest->isNotEmpty();
    $apptHref = $canSeeCalendar ? route('app.calendar.index') : null;
@endphp
<x-layouts.app title="Resources">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/resources.css') }}?v={{ filemtime(public_path('css/screens/resources.css')) }}">
    @endpush

    <div class="res">
        <x-ui.page-header class="res-header" title="Resources" description="Helpful guides, forms, and information to support your journey." icon="book-open" />

        <section class="res-panel" aria-label="Browse resources">
            <div class="res-bar">
                <nav class="res-tabs" aria-label="Resource categories">
                    <a href="{{ route('app.resources.index', $filters->query(['type' => null])) }}" class="res-tab{{ $filters->type === null ? ' is-active' : '' }}" @if ($filters->type === null) aria-current="page" @endif>All<span class="sr-only"> ({{ $page['total'] }})</span></a>
                    @foreach (ResourceType::cases() as $type)
                        <a href="{{ route('app.resources.index', $filters->query(['type' => $type->value])) }}" class="res-tab{{ $filters->type === $type ? ' is-active' : '' }}" @if ($filters->type === $type) aria-current="page" @endif>{{ $type->plural() }}<span class="sr-only"> ({{ $counts[$type->value] }})</span></a>
                    @endforeach
                </nav>
                <form method="GET" action="{{ route('app.resources.index') }}" role="search" class="res-search">
                    @if ($filters->type !== null)<input type="hidden" name="type" value="{{ $filters->type->value }}">@endif
                    @if ($filters->status !== null)<input type="hidden" name="status" value="{{ $filters->status->value }}">@endif
                    <button type="submit" class="res-search__submit" aria-label="Search resources"><x-ui.icon name="search" :size="18" /></button>
                    <label for="resource-search" class="sr-only">Search resources</label>
                    <input type="search" id="resource-search" name="q" value="{{ $filters->q }}" maxlength="100" placeholder="Search resources..." autocomplete="off" enterkeyhint="search">
                </form>
            </div>

            @if ($page['featured']->isNotEmpty())
                <ul class="res-featured" aria-label="Featured resources">
                    @foreach ($page['featured'] as $resource)
                        <li>
                            <a href="{{ route('app.resources.show', ['resource' => $resource]) }}" class="res-card">
                                <span class="res-tile res-tile--{{ $resource->type->value }}"><x-ui.icon :name="$resource->type->icon()" :size="22" :stroke="2.1" /></span>
                                <span class="res-card__title">{{ $resource->title }}</span>
                                <span class="res-card__text">{{ $resource->summary }}</span>
                                <span class="res-card__foot">
                                    <span class="res-card__meta"><x-ui.icon name="file-text" :size="16" /><span>{{ $resource->metaLine() }}</span></span>
                                    <x-ui.icon name="arrow-right" :size="17" class="res-card__go" />
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div class="res-columns">
            <section class="res-latest" aria-labelledby="res-latest-title">
                <header class="res-section-head">
                    <h2 id="res-latest-title">{{ $heading }}</h2>
                    <div class="res-section-head__links">
                        @if ($canManage)
                            @if ($filters->status === null)
                                <a href="{{ route('app.resources.index', $filters->query(['status' => ResourceStatus::Draft->value, 'view' => null])) }}" class="res-link">Drafts and archived</a>
                            @else
                                <a href="{{ route('app.resources.index', $filters->query(['status' => null])) }}" class="res-link">Published</a>
                            @endif
                            <a href="{{ route('app.resources.create') }}" class="res-link"><x-ui.icon name="plus" :size="14" />Add resource</a>
                        @endif
                        @if (! $listing)
                            <a href="{{ route('app.resources.index', $filters->query(['view' => 'all'])) }}" class="res-link">View all <x-ui.icon name="arrow-right" :size="14" /></a>
                        @else
                            <a href="{{ route('app.resources.index', array_filter(['type' => $filters->type?->value])) }}" class="res-link">Back to resources <x-ui.icon name="arrow-right" :size="14" /></a>
                        @endif
                    </div>
                </header>

                @if ($hasRows)
                    <ul class="res-list">
                        @foreach ($latest as $resource)
                            <li>
                                <a href="{{ route('app.resources.show', ['resource' => $resource]) }}" class="res-row">
                                    <span class="res-tile res-tile--{{ $resource->type->value }}"><x-ui.icon :name="$resource->type->icon()" :size="22" :stroke="2.1" /></span>
                                    <span class="res-row__text">
                                        <span class="res-row__title">{{ $resource->title }}@if ($resource->status !== ResourceStatus::Published) <x-ui.badge :tone="$resource->status->tone()">{{ $resource->status->label() }}</x-ui.badge>@endif</span>
                                        <span class="res-row__sub">{{ $resource->summary }}</span>
                                    </span>
                                    <span class="res-row__meta">
                                        <span><x-ui.icon :name="$resource->type->icon()" :size="12" />{{ $resource->type->label() }}</span>
                                        <span><x-ui.icon name="clock" :size="12" />@if ($resource->reading_minutes !== null){{ $resource->type->minutesLabel($resource->reading_minutes) }}@else<span class="sr-only">No reading time</span><span aria-hidden="true">—</span>@endif</span>
                                    </span>
                                    <x-ui.icon name="arrow-right" :size="15" class="res-row__go" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    @if ($latest instanceof \Illuminate\Contracts\Pagination\Paginator && $latest->hasPages())
                        <div class="res-pager">{{ $latest->links() }}</div>
                    @endif
                @else
                    <x-ui.empty-state icon="book-open"
                        :title="$filters->q !== '' ? 'No resources match' : 'No resources yet'"
                        :description="$filters->q !== '' ? 'Try different words, or clear the search to see everything.' : ($canManage ? 'Add your first guide, form, document, video or FAQ.' : 'Your organization has not published any resources yet.')">
                        <x-slot:actions>
                            @if ($filters->q !== '' || $filters->type !== null)
                                <x-ui.button variant="secondary" :href="route('app.resources.index')">Show all resources</x-ui.button>
                            @elseif ($canManage)
                                <x-ui.button icon="plus" :href="route('app.resources.create')">Add Resource</x-ui.button>
                            @endif
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            </section>

            <aside class="res-rail" aria-label="Help and shortcuts">
                @if ($quickLinks !== [])
                    <section class="res-quick" aria-labelledby="res-quick-title">
                        <h2 class="res-rail__title" id="res-quick-title"><x-ui.icon name="link" :size="22" />Quick Links</h2>
                        <ul class="res-quick__list">
                            @foreach ($quickLinks as $link)
                                <li><a href="{{ $link['href'] }}"><x-ui.icon :name="$link['icon']" :size="22" /><span>{{ $link['label'] }}</span><x-ui.icon name="chevron-right" :size="16" /></a></li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <section class="res-help" aria-labelledby="res-help-title">
                    <x-ui.logo :mark="true" :size="30" class="res-help__mark" />
                    <div>
                        <h2 id="res-help-title">Need Help?</h2>
                        <p>Our team is here to support you.<br>Reach out anytime.</p>
                        @if ($supportEmail !== null)<a href="mailto:{{ $supportEmail }}" class="res-help__btn">Contact Support</a>@endif
                    </div>
                </section>

                @if ($upcoming !== [])
                    <section class="res-upcoming" aria-labelledby="res-upcoming-title">
                        <header class="res-upcoming__head">
                            <h2 id="res-upcoming-title"><x-ui.icon name="calendar" :size="22" />Upcoming Appointments</h2>
                            @if ($apptHref)<a href="{{ $apptHref }}" class="res-link">View all <x-ui.icon name="arrow-right" :size="14" /></a>@endif
                        </header>
                        <ul>
                            @foreach ($upcoming as $a)
                                @php $start = fmt()->local($a['startsAt'], $a['timezone']); @endphp
                                <li>
                                    <a href="{{ route('app.appointments.show', ['appointment' => $a['id']]) }}" class="res-appt">
                                        <span class="res-appt__date" aria-hidden="true"><span>{{ mb_strtoupper($start->format('M')) }}</span><strong>{{ $start->format('j') }}</strong></span>
                                        <span class="res-appt__text">
                                            <span class="res-appt__title">{{ $a['service'] }}</span>
                                            <span class="res-appt__with">With {{ $a['client'] }}</span>
                                            <span class="res-appt__time"><x-ui.icon name="clock" :size="14" /><span class="sr-only">{{ fmt()->date($start) }}, </span>{{ fmt()->time($a['startsAt'], $a['timezone']) }} – {{ fmt()->time($a['endsAt'], $a['timezone']) }}</span>
                                        </span>
                                        <x-ui.icon name="chevron-right" :size="16" class="res-appt__go" />
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </aside>
        </div>
    </div>
</x-layouts.app>
