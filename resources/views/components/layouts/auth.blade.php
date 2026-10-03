@props([
    'title' => null,
    'previewShell' => null,
])
@php
    $shell = $previewShell ?? ($shell ?? []);
    $platformName = $shell['platformName'] ?? config('app.name', 'Carebase');
    $termsUrl = $shell['legal']['termsUrl'] ?? null;
    $privacyUrl = $shell['legal']['privacyUrl'] ?? null;
    $pageTitle = implode(' · ', array_filter([$title, $platformName]));
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('components.layouts.partials.head')
</head>
<body class="auth-body">
    <a href="#main" class="skip-link">Skip to main content</a>

    <div class="auth-shell">
        <header class="auth-brand">
            <a href="{{ url('/') }}" class="auth-brand__link" aria-label="{{ $platformName }} home"><x-ui.logo :name="$platformName" :size="34" /></a>
        </header>

        <main id="main" class="auth-card" tabindex="-1">
            <x-ui.flash />
            {{ $slot }}
        </main>

        @if (filled($termsUrl) || filled($privacyUrl))
            <footer class="auth-footer">
                <nav aria-label="Legal">
                    @if (filled($termsUrl))<a href="{{ $termsUrl }}">Terms of Service</a>@endif
                    @if (filled($termsUrl) && filled($privacyUrl))<span aria-hidden="true">&middot;</span>@endif
                    @if (filled($privacyUrl))<a href="{{ $privacyUrl }}">Privacy Policy</a>@endif
                </nav>
            </footer>
        @endif
    </div>

    @stack('scripts')
</body>
</html>
