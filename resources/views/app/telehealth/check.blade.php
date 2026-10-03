<x-layouts.app title="Test Connection">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/telehealth.css') }}?v={{ filemtime(public_path('css/screens/telehealth.css')) }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/screens/telehealth.js') }}?v={{ filemtime(public_path('js/screens/telehealth.js')) }}" defer></script>
    @endpush

    <div class="tj">
        <section class="tj-main" aria-labelledby="tj-title">
            <a href="{{ route('app.telehealth.index') }}" class="tj-back"><x-ui.icon name="arrow-left" :size="13" :stroke="2.2" />Back to Telehealth</a>
            <header class="tj-head">
                <span class="tj-head__tile"><x-ui.icon name="video" :size="28" :stroke="2" /></span>
                <div>
                    <h1 class="tj-head__title" id="tj-title">Test Connection</h1>
                    <p class="tj-head__text">Check your camera and microphone before a session. Nothing is recorded or sent anywhere.</p>
                </div>
            </header>
            @include('app.telehealth.partials-preview')
        </section>
        <aside class="tj-rail" aria-label="Tips">
            <section class="tj-card tj-before">
                <x-ui.icon name="shield-check" :size="24" :stroke="1.9" />
                <div>
                    <h2>Before you join</h2>
                    <p><span>Please make sure you're in a quiet, private space</span> <span>and have a stable internet connection.</span></p>
                </div>
            </section>
            <section class="tj-card tj-help">
                <span class="tj-help__q" aria-hidden="true">?</span>
                <div>
                    <h2>Need Help?</h2>
                    <p>If your camera or microphone is not found, check that no other app is using it and that this site is allowed to use it in your browser's address bar.</p>
                    @if ($supportEmail !== null)
                        <a href="mailto:{{ $supportEmail }}" class="tj-help__btn"><x-ui.icon name="headset" :size="19" :stroke="1.9" />Contact Support</a>
                    @endif
                </div>
            </section>
        </aside>
    </div>
</x-layouts.app>
