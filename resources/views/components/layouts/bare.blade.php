@props([
    'title' => null,
    'width' => 'md',
    'shell' => [],
])
@php
    // The frame behind the minimal layout. It is NOT bound to the $shell composer, so error pages that use it
    // keep rendering when the database or settings are unavailable. Every shell value has a fallback.
    $shell = is_array($shell) ? $shell : [];
    $platformName = $shell['platformName'] ?? config('app.name', 'WellNest');
    $user = $shell['user'] ?? null;
    $logoutUrl = $shell['logoutUrl'] ?? null;
    $width = in_array($width, ['sm', 'md', 'lg'], true) ? $width : 'md';
    $pageTitle = implode(' · ', array_filter([$title, $platformName]));
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('components.layouts.partials.head')
</head>
<body class="minimal-body">
    <a href="#main" class="skip-link">Skip to main content</a>

    <header class="minimal-header">
        <div class="minimal-header__inner">
            <a href="{{ url('/') }}" class="auth-brand__link" aria-label="{{ $platformName }} home"><x-ui.logo :name="$platformName"  /></a>
            @if (is_array($user) && filled($logoutUrl))
                <form method="POST" action="{{ $logoutUrl }}" class="minimal-header__signout">
                    @csrf
                    <x-ui.button type="submit" variant="ghost" size="sm" icon="log-out">Sign out</x-ui.button>
                </form>
            @endif
        </div>
    </header>

    <main id="main" class="minimal-main minimal-main--{{ $width }}" tabindex="-1">
        <x-ui.flash />
        {{ $slot }}
    </main>

    @stack('scripts')
</body>
</html>
