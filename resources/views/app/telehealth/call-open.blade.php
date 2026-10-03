{{-- The call page requested inside a frame (Sec-Fetch-Dest: iframe): the call page's own app frame, where the user browses while a call floats. No pass, no Daily — a call never nests or connects twice. telehealth.js asks the call page around this frame to bring its call back; when that is another session's call, it shows the second text instead. --}}
<x-layouts.app title="Telehealth call">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/telehealth.css') }}?v={{ filemtime(public_path('css/screens/telehealth.css')) }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/screens/telehealth.js') }}?v={{ filemtime(public_path('js/screens/telehealth.js')) }}" defer></script>
    @endpush

    <div class="tj-card tv-open" data-call-open="{{ parse_url(route('app.telehealth.call', ['session' => $session]), PHP_URL_PATH) }}">
        <x-ui.icon name="video" :size="22" :stroke="2" class="tv-open__icon" />
        <div data-call-open-same>
            <h1 class="tv-open__title">This call is already open</h1>
            <p class="tv-open__text">It keeps running in its own window in this tab.</p>
        </div>
        <div data-call-open-other hidden>
            <h1 class="tv-open__title">Another call is open</h1>
            <p class="tv-open__text">Only one video call can run in this tab. Opening this one ends the other call.</p>
        </div>
        <a href="{{ route('app.telehealth.call', ['session' => $session]) }}" target="_top" class="tj-btn tj-btn--outline">Open this call</a>
    </div>
</x-layouts.app>
