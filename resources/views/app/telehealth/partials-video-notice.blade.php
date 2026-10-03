{{-- Why video cannot be used right now (join and call pages). $state = demo|not_configured|unavailable|closed; $canManage shows what to configure. --}}
@php
    [$icon, $title, $text] = match ($state) {
        'demo' => ['flask-conical', 'Demo session — video is not connected', 'Demo records never reach the video service. Book a session for a real client to hold a call.'],
        'not_configured' => ['video-off', 'Video calls are not set up yet', $canManage
            ? 'The server needs a Daily API key (DAILY_API_KEY in its environment). Whoever runs your WellNest installation sets it; see Telehealth settings for the status.'
            : 'Ask your administrator to set up video calls.'],
        'closed' => ['video-off', 'The video room has closed', 'The time for this session\'s call is over.'],
        default => ['triangle-alert', 'The video service could not be reached', 'Refresh this page in a moment to try again. If it keeps happening, contact support.'],
    };
@endphp
<div class="tj-notice-box {{ $class ?? '' }}" role="status">
    <x-ui.icon :name="$icon" :size="20" :stroke="2" />
    <div>
        <p class="tj-notice-box__title">{{ $title }}</p>
        <p class="tj-notice-box__text">
            {{ $text }}
            @if ($state === 'not_configured' && $canManage && \Illuminate\Support\Facades\Route::has('app.settings.telehealth.edit'))
                <a href="{{ route('app.settings.telehealth.edit') }}">Telehealth settings</a>
            @endif
        </p>
    </div>
</div>
