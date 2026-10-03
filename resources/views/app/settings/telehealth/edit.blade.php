@php
    $statusTone = match ($videoStatus) {
        \App\Domain\Telehealth\Providers\VideoServiceStatus::Connected => 'success',
        \App\Domain\Telehealth\Providers\VideoServiceStatus::Fake => 'warning',
        default => 'neutral',
    };
@endphp
<x-layouts.app title="Telehealth settings">
    @include('app.settings.partials.open', ['current' => 'telehealth'])

    <form method="POST" action="{{ route('app.settings.telehealth.update') }}" class="set-form set-form--wide" data-submit-once novalidate>
        @csrf @method('PUT')
        @error('settings')<x-ui.alert tone="danger">{{ $message }}</x-ui.alert>@enderror
        <section class="set-card stack" aria-labelledby="th-video-title">
            <h2 class="set-card__title" id="th-video-title">Video service</h2>
            <x-ui.dl>
                <x-ui.dl-item label="Service">{{ $videoService }}</x-ui.dl-item>
                <x-ui.dl-item label="Status"><x-ui.badge :tone="$statusTone">{{ $videoStatus->label() }}</x-ui.badge></x-ui.dl-item>
            </x-ui.dl>
            @if ($videoStatus === \App\Domain\Telehealth\Providers\VideoServiceStatus::NotConfigured)
                <x-ui.alert tone="warning">Video calls are not set up on this server yet. Whoever runs your WellNest installation adds a Daily API key (DAILY_API_KEY) to the server's environment; until then sessions cannot be joined.</x-ui.alert>
            @elseif ($videoStatus === \App\Domain\Telehealth\Providers\VideoServiceStatus::Fake)
                <x-ui.alert tone="warning">Video is simulated for local development: rooms and calls are placeholders and nothing reaches Daily.</x-ui.alert>
            @endif
            <p class="text-sm text-muted">Calls open inside WellNest. Clients join with the session's link and always wait in a lobby until the clinician (or someone who manages telehealth) admits them. Chat in the call is off; use Messages for anything that belongs in the record.</p>
        </section>
        <section class="set-card">
            <div class="set-fields">
                <x-ui.field label="Join opens (minutes before the start)" name="join_early_minutes" id="th-early" help="Staff can join from this long before a session starts until it ends (0 to {{ \App\Domain\Telehealth\TelehealthSettings::MAX_EARLY_MINUTES }}). The client's link opens at the same time.">
                    <x-ui.input type="number" name="join_early_minutes" id="th-early" :value="$values['join_early_minutes']" min="0" :max="\App\Domain\Telehealth\TelehealthSettings::MAX_EARLY_MINUTES" />
                </x-ui.field>
                <div class="set-fields__full"><x-ui.toggle name="recording_enabled" id="th-recording" label="Allow session recordings" help="Off by default. Even when on, nothing is recorded unless the client's consent is recorded for that session; only the clinician (or someone with access to session notes) can start a recording, and it stays with Daily (or your own storage bucket configured with Daily) until it is deleted." :checked="$values['recording_enabled']" /></div>
                <div class="set-fields__full"><x-ui.toggle name="ai_transcripts_enabled" id="th-ai" label="Allow AI transcripts" help="Off by default. An AI transcript is always a draft until a clinician reviews it. Turn this on only after your agreement with an AI vendor is in place." :checked="$values['ai_transcripts_enabled']" /></div>
            </div>
        </section>
        <div class="form-actions"><x-ui.button type="submit">Save settings</x-ui.button></div>
    </form>
    @include('app.settings.partials.close')
</x-layouts.app>
