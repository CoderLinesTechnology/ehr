@php
    // $variant is "app" or "platform". $shell comes from the view composer; the styleguide passes a
    // preview-shell instead, and everything degrades to sensible defaults when neither exists (error pages).
    //
    // $shell keys consumed: platformName, announcement{message,level}, user{name,email,initials}, organization{name,slug,
    // logoUrl,isDemoDataPresent}, organizations[{name,url,current}], nav[], secondaryNav[], searchUrl, accountUrl, logoutUrl,
    // platformUrl, appUrl, roleLabel (top bar second line), unreadNotifications (int, red dot when > 0), notifications (list).
    $shell = $previewShell ?? ($shell ?? []);
    $isPlatform = $variant === 'platform';
    $platformName = $shell['platformName'] ?? config('app.name', 'WellNest');
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
    $unread = max(0, (int) ($shell['unreadNotifications'] ?? 0));
    $notifications = $shell['notifications'] ?? [];
    $roleLabel = $shell['roleLabel'] ?? ($isPlatform ? 'Super Admin' : ($org['name'] ?? ''));
    // One list, Settings last (as in the comps); the platform console keeps its "back" link in the same list.
    $allNav = array_values(array_merge($nav, $secondaryNav));
    if ($isPlatform && filled($appUrl)) {
        $allNav[] = ['key' => 'back', 'label' => 'Back to '.$platformName, 'icon' => 'arrow-left', 'url' => $appUrl, 'active' => false, 'badge' => null];
    }
    $homeUrl = $nav[0]['url'] ?? url('/');
    $pageTitle = implode(' · ', array_filter([$title, $isPlatform ? 'Platform' : ($org['name'] ?? null), $platformName]));
    $announcementLevel = ($announcement['level'] ?? 'info') === 'warning' ? 'warning' : 'info';
    $tabItems = array_slice($nav, 0, 3);
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
        <aside class="sidebar" id="sidebar" data-sidebar aria-label="Sidebar">
            <div class="sidebar__head">
                <a href="{{ $homeUrl }}" class="brand" aria-label="{{ $platformName }} home"><x-ui.logo :name="$platformName" /></a>
                <button type="button" class="icon-btn sidebar__close" data-sidebar-close aria-label="Close navigation"><x-ui.icon name="x" :size="20" /></button>
            </div>

            @if ($isPlatform)
                <p class="platform-chip"><x-ui.icon name="shield-check" :size="12" /> Super Admin console</p>
            @endif

            @if (count($organizations) > 1)
                <x-ui.dropdown class="org-switcher" align="left">
                    <x-slot:trigger>
                        <x-ui.icon name="building-2" :size="16" />
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

            @if ($allNav !== [])
                <nav class="sidebar__nav" aria-label="Main">
                    @include('components.layouts.partials.nav-list', ['items' => $allNav])
                </nav>
            @endif

            @unless ($isPlatform)
                <div class="sidebar__foot">
                    <div class="sidebar__card">
                        <x-ui.icon name="heart" :size="22" />
                        <p class="sidebar__card-title">Better care.</p>
                        <p class="sidebar__card-text">Healthier tomorrows.</p>
                    </div>
                </div>
            @endunless
        </aside>
        <div class="sidebar-backdrop" data-sidebar-close aria-hidden="true"></div>

        <div class="app-main" data-app-main>
            <header class="topbar">
                <button type="button" class="icon-btn topbar__menu" data-sidebar-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Open navigation"><x-ui.icon name="menu" :size="22" /></button>
                <a href="{{ $homeUrl }}" class="topbar__brand" aria-label="{{ $platformName }} home"><x-ui.logo :name="$platformName" size="sm" /></a>

                @if (filled($searchUrl))
                    <form class="topbar__search" role="search" method="GET" action="{{ $searchUrl }}">
                        <label for="global-search" class="sr-only">Search</label>
                        <x-ui.icon name="search" :size="18" :stroke="2" class="topbar__search-icon" />
                        <input type="search" id="global-search" name="q" class="topbar__search-input" placeholder="Search clients, appointments, or anything..." autocomplete="off" enterkeyhint="search">
                    </form>
                @endif

                <div class="topbar__actions">
                    <x-ui.notifications :items="$notifications" :unread="$unread" />

                    @if (is_array($user))
                        <span class="topbar__divider" aria-hidden="true"></span>
                        <x-ui.dropdown align="right" class="user-menu">
                            <x-slot:trigger>
                                <x-ui.avatar :name="$user['name'] ?? ''" size="shell" tone="brand" :decorative="true" />
                                <span class="user-menu__text">
                                    <span class="sr-only">Account menu for </span>
                                    <span class="user-menu__name">{{ $user['name'] ?? '' }}</span>
                                    <span class="user-menu__role">{{ $roleLabel }}</span>
                                </span>
                                <x-ui.icon name="chevron-down" :size="16" class="user-menu__caret" />
                            </x-slot:trigger>
                            <div class="dropdown__header dropdown__header--user">
                                <strong>{{ $user['name'] ?? '' }}</strong>
                                <span>{{ $user['email'] ?? '' }}</span>
                            </div>
                            @if (filled($accountUrl))<x-ui.dropdown-item :href="$accountUrl" icon="user">Account</x-ui.dropdown-item>@endif
                            @if (filled($platformUrl))<x-ui.dropdown-item :href="$platformUrl" icon="shield-check">Super Admin console</x-ui.dropdown-item>@endif
                            @if ($isPlatform && filled($appUrl))<x-ui.dropdown-item :href="$appUrl" icon="arrow-left">Back to {{ $platformName }}</x-ui.dropdown-item>@endif
                            @if (filled($logoutUrl))
                                <div class="dropdown__sep" role="separator"></div>
                                <x-ui.dropdown-item :action="$logoutUrl" method="POST" icon="log-out">Sign out</x-ui.dropdown-item>
                            @endif
                        </x-ui.dropdown>
                    @endif
                </div>
            </header>

            @if (is_array($announcement) && filled($announcement['message'] ?? null))
                <div class="banner banner--{{ $announcementLevel }}" role="region" aria-label="Platform announcement">
                    <x-ui.icon :name="$announcementLevel === 'warning' ? 'triangle-alert' : 'info'" :size="16" />
                    <p>{{ $announcement['message'] }}</p>
                </div>
            @endif
            @if (! empty($org['isDemoDataPresent']))
                <div class="banner banner--demo" role="status">
                    <x-ui.icon name="flask-conical" :size="16" />
                    <p><strong>Demo data is loaded</strong> &mdash; demo records are marked <span class="banner__tag">DEMO</span> and excluded from reports.</p>
                </div>
            @endif

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

    @if ($tabItems !== [])
        <nav class="tabbar" aria-label="Quick navigation">
            @foreach ($tabItems as $item)
                <a href="{{ filled($item['url'] ?? null) ? $item['url'] : '#' }}" class="tabbar__item" @if (! empty($item['active'])) aria-current="page" @endif>
                    <x-ui.icon :name="$item['icon'] ?? 'layers'" :size="22" /><span>{{ $item['label'] ?? '' }}</span>
                </a>
            @endforeach
            <button type="button" class="tabbar__item" data-sidebar-toggle aria-controls="sidebar" aria-expanded="false"><x-ui.icon name="menu" :size="22" /><span>More</span></button>
        </nav>
    @endif

    @stack('scripts')
</body>
</html>
