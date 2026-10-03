{{-- The call (spec docs/design/spec/screens/12-telehealth-call.md): Daily Prebuilt in a plain iframe, no Daily script in this page. The frame URL carries this viewer's pass: it is printed here only (the page is no-store). --}}
@php
    $format = app(\App\Support\Formatter::class);
    $startsAt = $format->localDate($details->startsAt, $details->timezone);
    $range = $format->time($details->startsAt, $details->timezone).' – '.$format->time($details->endsAt, $details->timezone);
@endphp
<x-layouts.app title="Telehealth call">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/telehealth.css') }}?v={{ filemtime(public_path('css/screens/telehealth.css')) }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/screens/telehealth.js') }}?v={{ filemtime(public_path('js/screens/telehealth.js')) }}" defer></script>
    @endpush

    <div class="tv">
        <section class="tv-main" aria-labelledby="tv-title">
            <header class="tv-head">
                <a href="{{ route('app.telehealth.join', ['session' => $session]) }}" class="tj-back"><x-ui.icon name="arrow-left" :size="14" :stroke="2.3" />Back to session</a>
                <div class="tv-head__row">
                    @include('app.telehealth.partials-avatar', ['name' => $details->clientName, 'number' => $details->clientNumber, 'size' => 'lg', 'class' => 'tv-head__avatar'])
                    <div class="tv-head__text">
                        <h1 class="tv-head__title" id="tv-title">{{ $details->clientName }}</h1>
                        <p class="tv-head__when">{{ $details->serviceName }} <i aria-hidden="true">•</i> {{ $startsAt }} <i aria-hidden="true">•</i> {{ $range }}</p>
                    </div>
                    <span class="tele-pill tele-pill--warning tv-head__pill">In progress</span>
                </div>
            </header>

            @if ($pass !== null)
                <div class="tv-stage">
                    <iframe class="tv-frame" src="{{ $pass->frameUrl }}" title="Video call with {{ $details->clientName }}"
                        allow="camera; microphone; autoplay; display-capture; fullscreen" allowfullscreen referrerpolicy="no-referrer"></iframe>
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

            <div class="tv-end">
                <x-ui.confirm-form :action="route('app.telehealth.end', ['session' => $session])" title="End this session?"
                    message="Everyone is removed from the call and the session is marked completed. This cannot be undone."
                    confirmLabel="End session" buttonLabel="End session" buttonVariant="secondary" buttonIcon="phone-off" />
            </div>
        </aside>
    </div>
</x-layouts.app>
