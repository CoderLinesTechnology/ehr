{{-- Opens the Settings frame: page header, section nav, panel heading. Close with partials.close. $current = section key; push buttons to the settings-actions stack BEFORE including this. --}}
@php
    $sections = \App\Http\Controllers\App\Settings\SettingsSections::visible($current);
    $active = collect($sections)->firstWhere('key', $current);
    $panelTitle = $panelTitle ?? ($active['title'] ?? 'Settings');
    $panelSub = $panelSub ?? ($active['description'] ?? null);
@endphp
@push('styles')<link rel="stylesheet" href="{{ asset('css/screens/settings.css') }}">@endpush
@push('scripts')<script src="{{ asset('js/screens/settings.js') }}" defer></script>@endpush

<x-ui.page-header title="Settings" description="Manage your organization's settings and preferences." icon="settings" />

<div class="set-layout">
    <nav class="set-nav" aria-label="Settings sections">
        <ul class="set-nav__list">
            @foreach ($sections as $section)
                <li>
                    <a href="{{ $section['url'] }}" class="set-nav__link" @if ($section['active']) aria-current="page" @endif>
                        <x-ui.icon :name="$section['icon']" :size="18" class="set-nav__icon" />
                        <span class="set-nav__label">{{ $section['label'] }}</span>
                        <x-ui.icon name="chevron-right" :size="14" class="set-nav__chev" />
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    <section class="set-panel" aria-labelledby="set-panel-title">
        <header class="set-panel__head">
            <div>
                <h2 class="set-panel__title" id="set-panel-title">{{ $panelTitle }}</h2>
                @if (filled($panelSub))<p class="set-panel__sub">{{ $panelSub }}</p>@endif
            </div>
            <div class="set-panel__actions">@stack('settings-actions')</div>
        </header>
