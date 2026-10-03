@php
    // Asset URLs carry the file's modification time, so a deployed change is picked up at once without a build step.
    $assetUrl = static function (string $path): string {
        $file = public_path($path);

        return asset($path).(is_file($file) ? '?v='.filemtime($file) : '');
    };
@endphp
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="color-scheme" content="light dark">
<meta name="robots" content="noindex, nofollow">
<title>{{ $pageTitle }}</title>
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="stylesheet" href="{{ $assetUrl('css/app.css') }}">
{{-- Without JavaScript nothing can dismiss a toast, so confirmations fall back to ordinary in-page messages. --}}
<noscript><style>.flash-region--toast { position: static; width: auto; margin-bottom: 1.25rem; pointer-events: auto; }</style></noscript>
@stack('styles')
<script src="{{ $assetUrl('js/app.js') }}" defer></script>
