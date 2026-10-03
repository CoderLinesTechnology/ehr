@php
    // $variant is "app" or "platform". $shell comes from the view composer; the styleguide passes a
    // preview-shell instead, and everything degrades to sensible defaults when neither exists (error pages).
    $shell = $previewShell ?? ($shell ?? []);
    $isPlatform = $variant === 'platform';
    $platformName = $shell['platformName'] ?? config('app.name', 'Carebase');
    $user = $shell['user'] ?? null;
    $org = $isPlatform ? null : ($shell['organization'] ?? null);
    $nav = $shell['nav'] ?? [];
    $secondaryNav = $shell['secondaryNav'] ?? [];
    $organizations = $shell['organizations'] ?? [];
    $announcement = $shell['announcement'] ?? null;
    $searchUrl = $shell['searchUrl'] ?? null;
    $accountUrl = $shell['accountUrl'] ?? null;
    $logoutUrl = $shell['logoutUrl'] ?? null;
    $platformUrl = $shell['platformUrl'] ?? null;
    $appUrl = $shell['appUrl'] ?? null;
    $logoUrl = $org['logoUrl'] ?? null;
    $brandName = $isPlatform ? $platformName : ($org['name'] ?? $platformName);
    $orgInitials = mb_strtoupper(implode('', array_map(
        static fn ($w) => mb_substr($w, 0, 1),
        array_slice(preg_split('/\s+/u', trim((string) $brandName), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'], 0, 2)
    )));
    $homeUrl = $nav[0]['url'] ?? url('/');
    $pageTitle = implode(' · ', array_filter([$title, $isPlatform ? 'Platform' : ($org['name'] ?? null), $platformName]));
    $announcementLevel = ($announcement['level'] ?? 'info') === 'warning' ? 'warning' : 'info';
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('components.layouts.partials.head')
    {{-- Without JavaScript the off-canvas menu cannot open, so on small screens the navigation simply stays in the page flow. --}}
    <noscript><style>@media (max-width: 63.999rem) { .app-shell { display: block; } .sidebar { position: static; transform: none; visibility: visible; width: auto; height: auto; } .topbar__menu, .sidebar__close, .sidebar-backdrop { display: none; } }</style></noscript>
</head>
<body @class(['app-body--platform' => $isPlatform]) data-shell="{{ $variant }}">
    <a href="#main" class="skip-link">Skip to main content</a>

    <div class="app-shell" data-app-shell>
        <div class="sidebar" id="sidebar" data-sidebar>
            <div class="sidebar__head">
                <a href="{{ $homeUrl }}" class="brand">
                    @if (filled($logoUrl))
                        <img src="{{ $logoUrl }}" alt="" class="brand__logo" width="36" height="36">
                    @elseif ($isPlatform)
                        <span class="brand__mark brand__mark--platform" aria-hidden="true"><x-ui.icon name="shield" :size="18" /></span>
                    @else
                        <span class="brand__mark" aria-hidden="true">{{ $orgInitials }}</span>
                    @endif
                    <span class="brand__text">
                        <span class="brand__name">{{ $brandName }}</span>
                        <span class="brand__sub">{{ $isPlatform ? $platformName : ($org ? $platformName : '') }}</span>
                    </span>
                </a>
                <button type="button" class="icon-btn sidebar__close" data-sidebar-close aria-label="Close navigation"><x-ui.icon name="x" :size="18" /></button>
            </div>

            @if ($isPlatform)
                <p class="platform-chip"><x-ui.icon name="shield" :size="12" /> Platform console</p>
            @endif

            @if (count($organizations) > 1)
                <x-ui.dropdown class="org-switcher" align="left">
                    <x-slot:trigger>
                        <x-ui.icon name="building" :size="16" />
                        <span class="org-switcher__label">Switch organization</span>
                        <x-ui.icon name="chevron-down" :size="16" />
                    </x-slot:trigger>
                    <p class="dropdown__header">Your organizations</p>
                    @foreach ($organizations as $organization)
                        <a href="{{ $organization['url'] ?? '#' }}" class="dropdown__item" @if (! empty($organization['current'])) aria-current="true" @endif>
                            <span class="dropdown__item-label">{{ $organization['name'] ?? '' }}</span>
                            @if (! empty($organization['current']))<x-ui.icon name="check" :size="16" /><span class="sr-only">(current)</span>@endif
                        </a>
                    @endforeach
                </x-ui.dropdown>
            @endif

            @if ($nav !== [])
                <nav class="sidebar__nav" aria-label="Main">
                    @include('components.layouts.partials.nav-list', ['items' => $nav])
                </nav>
            @endif

            @if ($secondaryNav !== [] || ($isPlatform && filled($appUrl)))
                <nav class="sidebar__nav sidebar__nav--bottom" aria-label="Secondary">
                    @include('components.layouts.partials.nav-list', ['items' => $secondaryNav])
                    @if ($isPlatform && filled($appUrl))
                        <ul class="nav-list" role="list">
                            <li><a href="{{ $appUrl }}" class="nav-link"><x-ui.icon name="arrow-left" :size="18" /><span class="nav-link__label">Back to {{ $platformName }}</span></a></li>
                        </ul>
                    @endif
                </nav>
            @endif
        </div>
        <div class="sidebar-backdrop" data-sidebar-close aria-hidden="true"></div>

        <div class="app-main" data-app-main>
            @if (is_array($announcement) && filled($announcement['message'] ?? null))
                <div class="banner banner--{{ $announcementLevel }}" role="region" aria-label="Platform announcement">
                    <x-ui.icon :name="$announcementLevel === 'warning' ? 'alert-triangle' : 'info'" :size="16" />
                    <p>{{ $announcement['message'] }}</p>
                </div>
            @endif
            @if (! empty($org['isDemoDataPresent']))
                <div class="banner banner--demo" role="status">
                    <x-ui.icon name="flask" :size="16" />
                    <p><strong>Demo data is loaded</strong> &mdash; demo records are marked <span class="banner__tag">DEMO</span> and excluded from reports.</p>
                </div>
            @endif

            <header class="topbar">
                <button type="button" class="icon-btn topbar__menu" data-sidebar-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Open navigation"><x-ui.icon name="menu" :size="20" /></button>

                @if (filled($searchUrl))
                    <form class="topbar__search" role="search" method="GET" action="{{ $searchUrl }}">
                        <label for="global-search" class="sr-only">Search</label>
                        <x-ui.icon name="search" :size="16" class="topbar__search-icon" />
                        <input type="search" id="global-search" name="q" class="topbar__search-input" placeholder="Search" autocomplete="off" enterkeyhint="search">
                    </form>
                @endif

                <div class="topbar__actions">
                    {{-- Revealed by app.js; hidden attribute keeps a dead control out of the page without JavaScript. --}}
                    <button type="button" class="icon-btn theme-toggle" data-theme-toggle hidden aria-pressed="false" aria-label="Dark mode">
                        <x-ui.icon name="moon" :size="18" class="theme-toggle__moon" /><x-ui.icon name="sun" :size="18" class="theme-toggle__sun" />
                    </button>

                    <x-ui.dropdown label="Notifications" icon="bell" align="right" class="notifications">
                        <div class="dropdown__empty">
                            <x-ui.icon name="bell" :size="20" />
                            <p class="dropdown__empty-title">You are all caught up</p>
                            <p>There are no new notifications.</p>
                        </div>
                    </x-ui.dropdown>

                    @if (is_array($user))
                        <x-ui.dropdown align="right" class="user-menu">
                            <x-slot:trigger>
                                <x-ui.avatar :name="$user['name'] ?? ''" size="sm" :decorative="true" />
                                <span class="sr-only">Account menu for </span><span class="user-menu__name">{{ $user['name'] ?? '' }}</span>
                                <x-ui.icon name="chevron-down" :size="16" class="user-menu__caret" />
                            </x-slot:trigger>
                            <div class="dropdown__header dropdown__header--user">
                                <strong>{{ $user['name'] ?? '' }}</strong>
                                <span>{{ $user['email'] ?? '' }}</span>
                            </div>
                            @if (filled($accountUrl))<x-ui.dropdown-item :href="$accountUrl" icon="user">Account</x-ui.dropdown-item>@endif
                            @if (filled($platformUrl))<x-ui.dropdown-item :href="$platformUrl" icon="shield">Super Admin console</x-ui.dropdown-item>@endif
                            @if ($isPlatform && filled($appUrl))<x-ui.dropdown-item :href="$appUrl" icon="arrow-left">Back to {{ $platformName }}</x-ui.dropdown-item>@endif
                            @if (filled($logoutUrl))
                                <div class="dropdown__sep" role="separator"></div>
                                <x-ui.dropdown-item :action="$logoutUrl" method="POST" icon="log-out">Sign out</x-ui.dropdown-item>
                            @endif
                        </x-ui.dropdown>
                    @endif
                </div>
            </header>

            <main id="main" @class(['app-content', 'app-content--wide' => $wide]) tabindex="-1">
                @if (isset($breadcrumbs) && ! $breadcrumbs->isEmpty())
                    <div class="app-content__crumbs">{{ $breadcrumbs }}</div>
                @endif
                @if (isset($header) && ! $header->isEmpty())
                    {{ $header }}
                @endif
                <x-ui.flash />
                {{ $slot }}
            </main>
        </div>
    </div>

    @stack('scripts')
</body>
</html>
