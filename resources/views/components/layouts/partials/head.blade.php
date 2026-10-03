@php
    // Asset URLs carry the file's modification time, so a deployed change is picked up at once without a build step.
    $assetUrl = static function (string $path): string {
        $file = public_path($path);

        return asset($path).(is_file($file) ? '?v='.filemtime($file) : '');
    };
@endphp
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="color-scheme" content="light">
<meta name="theme-color" content="#f4f8fb">
<meta name="robots" content="noindex, nofollow">
<title>{{ $pageTitle }}</title>
<link rel="icon" href="{{ asset('images/wellnest-mark.svg') }}" type="image/svg+xml">
<link rel="preload" href="{{ asset('fonts/inter/inter-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ $assetUrl('css/app.css') }}">
{{-- Without JavaScript nothing can dismiss a toast, so confirmations fall back to ordinary in-page messages. --}}
<noscript><style>.flash-region--toast { position: static; width: auto; margin-bottom: 1.25rem; pointer-events: auto; }</style></noscript>
@stack('styles')
<script src="{{ $assetUrl('js/app.js') }}" defer></script>
