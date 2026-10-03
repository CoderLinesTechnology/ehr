@php
    $format = app(\App\Support\Formatter::class);
    $startsAt = $format->localDate($details->startsAt, $details->timezone);
    $range = $format->time($details->startsAt, $details->timezone).' – '.$format->time($details->endsAt, $details->timezone);
    $running = $session->status === \App\Domain\Telehealth\SessionStatus::InProgress;
    $ready = $video === 'ready' && $clientLink !== null;
    $canJoinNow = $ready && ($details->joinWindowOpen || $running);
    $opensAt = \App\Domain\Telehealth\JoinWindow::opensAt($details->startsAt, $details->joinEarlyMinutes);
    $headline = match (true) {
        $running && $ready => 'This session is in progress. You can rejoin below.',
        $video === 'demo' => 'Demo session — video is not connected.',
        $video === 'not_configured' => 'Video calls are not set up yet.',
        $video === 'unavailable' => 'The video service could not be reached right now.',
        $video === 'closed' || now()->greaterThanOrEqualTo($details->endsAt) => 'The time for this session has passed.',
        $canJoinNow => 'Your session is ready. Click the button below to join.',
        default => 'You can join from '.$format->time($opensAt, $details->timezone).'.',
    };
@endphp
<x-layouts.app title="Join Telehealth Session">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/telehealth.css') }}?v={{ filemtime(public_path('css/screens/telehealth.css')) }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/screens/telehealth.js') }}?v={{ filemtime(public_path('js/screens/telehealth.js')) }}" defer></script>
    @endpush

    <div class="tj">
        <section class="tj-main" aria-labelledby="tj-title">
            <a href="{{ $calendarUrl }}" class="tj-back"><x-ui.icon name="arrow-left" :size="14" :stroke="2.3" />Back to Appointments</a>

            <header class="tj-head">
                <span class="tj-head__tile"><x-ui.icon name="video" :size="28" :stroke="2" /></span>
                <div>
                    <h1 class="tj-head__title" id="tj-title">Join Telehealth Session</h1>
                    <p class="tj-head__text">{{ $headline }}</p>
                </div>
            </header>

            <div class="tj-client">
                <div class="tj-client__who">
                    @include('app.telehealth.partials-avatar', ['name' => $details->clientName, 'number' => $details->clientNumber, 'size' => 'xl', 'class' => 'tj-client__avatar'])
                    <div class="tj-client__text">
                        <span class="tj-client__name">{{ $details->clientName }}</span>
                        <span class="tj-client__service">{{ $details->serviceName }}</span>
                        <span class="tj-client__when"><x-ui.icon name="calendar" :size="14" :stroke="1.9" />{{ $startsAt }} <i aria-hidden="true">•</i> {{ $range }}</span>
                    </div>
                </div>
                <div class="tj-client__cell">
                    <x-ui.icon name="video" :size="24" :stroke="1.8" />
                    <span><b>Video</b><small>{{ $details->vendor }} Meeting</small></span>
                </div>
                <div class="tj-client__cell">
                    <x-ui.icon name="map-pin" :size="22" :stroke="1.8" />
                    <span><b>Location</b><small>Telehealth</small></span>
                </div>
            </div>

            @include('app.telehealth.partials-preview')

            @if (! $ready)
                @include('app.telehealth.partials-video-notice', ['state' => $video, 'canManage' => $canManage, 'class' => 'tj-notice'])
            @endif

            @if ($canJoinNow)
                <form method="POST" action="{{ route('app.telehealth.start', ['session' => $session]) }}" class="tj-join-form" data-telehealth-join data-submit-once>
                    @csrf
                    <button type="submit" class="tj-join"><x-ui.icon name="video" :size="19" :stroke="2" />{{ $running ? 'Rejoin Session' : 'Join Session' }}</button>
                </form>
            @else
                <span class="tj-join is-disabled" aria-disabled="true"><x-ui.icon name="video" :size="19" :stroke="2" />Join Session</span>
                @if ($ready)
                    <p class="tj-hint">You can join from {{ $format->time($opensAt, $details->timezone) }} until the session ends at {{ $format->time($details->endsAt, $details->timezone) }}.</p>
                @endif
            @endif

            @if ($ready)
                <button type="button" class="tj-copy" data-telehealth-copy data-url="{{ $clientLink }}"><x-ui.icon name="link" :size="19" :stroke="2" /><span data-copy-label>Copy Meeting Link</span></button>
                <p class="tj-hint tj-hint--lobby">Clients who open this link wait in the lobby until you admit them.</p>
            @endif

            @if ($running)
                <form method="POST" action="{{ route('app.telehealth.end', ['session' => $session]) }}" class="tj-end" data-submit-once>
                    @csrf
                    <button type="submit" class="tj-btn tj-btn--outline">End session</button>
                </form>
            @endif

            @if ($recordingOn)
                <form method="POST" action="{{ route('app.telehealth.consent', ['session' => $session]) }}" class="tj-consent" data-submit-once>
                    @csrf @method('PUT')
                    <input type="hidden" name="consent" value="{{ $session->consent_to_record ? '0' : '1' }}">
                    <p><strong>Recording.</strong> {{ $session->consent_to_record ? 'The client agreed to this session being recorded.' : 'Nothing is recorded unless the client agrees. Record their consent here first.' }}</p>
                    <button type="submit" class="tj-btn tj-btn--outline">{{ $session->consent_to_record ? 'Withdraw consent' : 'Client consents to recording' }}</button>
                </form>
            @endif
        </section>

        <aside class="tj-rail" aria-label="Session information">
            @include('app.telehealth.partials-details')

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
                    <p><span>If you're having trouble joining, contact support</span> <span>or try refreshing this page.</span></p>
                    @if ($supportEmail !== null)
                        <a href="mailto:{{ $supportEmail }}" class="tj-help__btn"><x-ui.icon name="headset" :size="19" :stroke="1.9" />Contact Support</a>
                    @endif
                </div>
            </section>

            <section class="tj-card tj-thanks">
                <x-ui.logo :mark="true" :size="30" class="tj-thanks__mark" />
                <div>
                    <h2>You're making a difference</h2>
                    <p><span>Thank you for being part of your client's</span> <span>journey to better health.</span></p>
                </div>
            </section>
        </aside>
    </div>
</x-layouts.app>
