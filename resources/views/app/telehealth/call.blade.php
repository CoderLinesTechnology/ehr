{{-- The call (spec docs/design/spec/screens/12-telehealth-call.md): Daily Prebuilt in a plain iframe, no Daily script in this page. The frame URL carries this viewer's pass: it is printed here only (the page is no-store).
     With a call, the page is also the "call host": links into the app open in the app frame (wellnest-app) and the call floats above it. The Daily frame never moves in the DOM (a moved iframe reloads and drops the call): its dock stays in the stage and only classes and inline geometry change. --}}
@php
    $format = app(\App\Support\Formatter::class);
    $startsAt = $format->localDate($details->startsAt, $details->timezone);
    $range = $format->time($details->startsAt, $details->timezone).' – '.$format->time($details->endsAt, $details->timezone);
    $host = $pass !== null ? [
        'base' => parse_url(route('app.dashboard'), PHP_URL_PATH),
        'call' => parse_url(route('app.telehealth.call', ['session' => $session]), PHP_URL_PATH),
        'join' => parse_url(route('app.telehealth.join', ['session' => $session]), PHP_URL_PATH),
    ] : null;
@endphp
<x-layouts.app title="Telehealth call">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/telehealth.css') }}?v={{ filemtime(public_path('css/screens/telehealth.css')) }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/screens/telehealth.js') }}?v={{ filemtime(public_path('js/screens/telehealth.js')) }}" defer></script>
        <script src="{{ asset('js/screens/telehealth-call.js') }}?v={{ filemtime(public_path('js/screens/telehealth-call.js')) }}" defer></script>
    @endpush

    <div class="tv" @if ($host !== null) data-call-host="{{ $host['base'] }}" data-call-path="{{ $host['call'] }}" data-call-join="{{ $host['join'] }}" @if ($appPath !== null) data-call-app="{{ $appPath }}" @endif @endif>
        <section class="tv-main" aria-labelledby="tv-title">
            <header class="tv-head">
                <a href="{{ route('app.telehealth.join', ['session' => $session]) }}" class="tj-back"><x-ui.icon name="arrow-left" :size="14" :stroke="2.3" />Back to session</a>
                <div class="tv-head__row">
                    @include('app.telehealth.partials-avatar', ['name' => $details->clientName, 'number' => $details->clientNumber, 'size' => 'lg', 'class' => 'tv-head__avatar'])
                    <div class="tv-head__text">
                        <h1 class="tv-head__title" id="tv-title" tabindex="-1">{{ $details->clientName }}</h1>
                        <p class="tv-head__when">{{ $details->serviceName }} <i aria-hidden="true">•</i> {{ $startsAt }} <i aria-hidden="true">•</i> {{ $range }}</p>
                    </div>
                    <span class="tele-pill tele-pill--warning tv-head__pill">In progress</span>
                    @if ($pass !== null)
                        {{-- Revealed by the script when the browser allows full screen. --}}
                        <button type="button" class="tj-btn tj-btn--outline tv-head__fs" data-call-fullscreen aria-pressed="false" title="Full screen" hidden><x-ui.icon name="maximize" :size="15" :stroke="2" /><span class="tv-head__fs-label">Full screen</span></button>
                    @endif
                </div>
            </header>

            @if ($pass !== null)
                <div class="tv-stage">
                    <div class="tv-dock" role="region" aria-label="Video call with {{ $details->clientName }}" data-call-dock>
                        {{-- The window bar: shown while the call floats over the app, and in full screen. --}}
                        <div class="tv-bar" data-call-bar>
                            <span class="tv-bar__name">{{ $details->clientName }}</span>
                            <button type="button" class="tv-bar__btn" data-call-expand aria-label="Expand call" title="Expand call"><x-ui.icon name="maximize-2" :size="16" :stroke="2" /></button>
                            <button type="button" class="tv-bar__btn" data-call-fullscreen aria-label="Full screen" aria-pressed="false" title="Full screen" hidden><x-ui.icon name="maximize" :size="16" :stroke="2" /></button>
                            <button type="button" class="tv-bar__btn" data-call-move aria-label="Move to next corner" title="Move to next corner"><x-ui.icon name="move" :size="16" :stroke="2" /></button>
                            <button type="button" class="tv-bar__btn tv-bar__btn--end" data-call-leave aria-label="Leave call" title="Leave call (the session stays open)"><x-ui.icon name="phone-off" :size="16" :stroke="2" /></button>
                        </div>
                        <iframe class="tv-frame" src="{{ $pass->frameUrl }}" title="Video call with {{ $details->clientName }}"
                            allow="camera; microphone; autoplay; display-capture; fullscreen" allowfullscreen referrerpolicy="no-referrer"></iframe>
                        <p class="sr-only" role="status" data-call-status></p>
                    </div>
                </div>
            @else
                <div class="tv-stage tv-stage--empty">
                    @include('app.telehealth.partials-video-notice', ['state' => $video === 'ready' ? 'unavailable' : $video, 'canManage' => $canManage, 'class' => 'tv-notice'])
                    @if (! in_array($video, ['demo', 'not_configured', 'closed'], true))
                        <a href="{{ route('app.telehealth.call', ['session' => $session]) }}" class="tj-btn tj-btn--outline">Try again</a>
                    @endif
                </div>
            @endif
        </section>

        <aside class="tv-rail" aria-label="Session information">
            @include('app.telehealth.partials-details', ['compact' => true])

            @if ($clientLink !== null)
                <section class="tj-card tv-card" aria-labelledby="tv-link-title">
                    <h2 class="tv-card__title" id="tv-link-title"><x-ui.icon name="link" :size="18" :stroke="2" />Client link</h2>
                    <p class="tv-card__text">Send this link to {{ $details->clientName }}. They wait in the lobby until you admit them from the call.</p>
                    <button type="button" class="tj-btn tj-btn--outline tv-card__btn" data-telehealth-copy data-url="{{ $clientLink }}"><x-ui.icon name="copy" :size="15" :stroke="2" /><span data-copy-label>Copy Meeting Link</span></button>
                </section>
            @endif

            @if ($recordingOn)
                <section class="tj-card tv-card" aria-labelledby="tv-rec-title">
                    <h2 class="tv-card__title" id="tv-rec-title"><x-ui.icon name="circle-dot" :size="18" :stroke="2" />Recording</h2>
                    @if ($session->consent_to_record)
                        <p class="tv-card__text"><span class="tele-pill tele-pill--success">Client consented</span></p>
                        <p class="tv-card__text">{{ $clinical ? 'You can start a recording with Record in the call. Everyone in the call sees that it is recorded.' : 'The clinician can record this call.' }}</p>
                    @else
                        <p class="tv-card__text"><span class="tele-pill tele-pill--neutral">Not recorded</span></p>
                        <p class="tv-card__text">Nothing can be recorded unless the client agrees.</p>
                    @endif
                    @if ($clinical)
                        <form method="POST" action="{{ route('app.telehealth.consent', ['session' => $session]) }}" data-submit-once>
                            @csrf @method('PUT')
                            <input type="hidden" name="consent" value="{{ $session->consent_to_record ? '0' : '1' }}">
                            <button type="submit" class="tj-btn tj-btn--outline tv-card__btn">{{ $session->consent_to_record ? 'Withdraw consent (stops recording)' : 'Client consents to recording' }}</button>
                        </form>
                        @error('consent')<p class="tj-error" role="alert">{{ $message }}</p>@enderror
                    @endif
                </section>
            @endif

            {{-- Leaving the call is not finishing the session: the session stays in progress (rejoin from the join page) until
                 someone completes it. --}}
            <div class="tv-end">
                <x-ui.button variant="secondary" :href="route('app.telehealth.join', ['session' => $session])" icon="phone-off" class="tv-leave" data-call-leave>Leave call</x-ui.button>
                <div class="tv-complete">
                    <x-ui.confirm-form :action="route('app.telehealth.end', ['session' => $session])" title="Complete this session?"
                        message="The call ends for everyone, and the session and its appointment are marked completed. This cannot be undone."
                        confirmLabel="Complete session" buttonLabel="Complete session" buttonVariant="secondary" buttonIcon="circle-check" />
                </div>
                <p class="tv-end__hint">Leaving keeps the session open so you can rejoin. Complete it when the visit is over.</p>
            </div>
        </aside>
    </div>

    @if ($pass !== null)
        {{-- The app frame: other WellNest pages open here (same origin, normal pages with their own scripts) while the call floats. No camera, microphone or screen capture inside it: the call owns them. --}}
        <iframe name="wellnest-app" class="tv-appframe" title="WellNest" allow="camera 'none'; microphone 'none'; display-capture 'none'" hidden data-call-app-frame></iframe>
        <div class="tv-float-area" aria-hidden="true" data-call-area></div>
    @endif
</x-layouts.app>
