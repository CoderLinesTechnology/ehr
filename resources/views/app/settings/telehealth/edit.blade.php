<x-layouts.app title="Telehealth settings">
    @include('app.settings.partials.open', ['current' => 'telehealth'])

    <form method="POST" action="{{ route('app.settings.telehealth.update') }}" class="set-form set-form--wide" data-submit-once novalidate>
        @csrf @method('PUT')
        @error('settings')<x-ui.alert tone="danger">{{ $message }}</x-ui.alert>@enderror
        <x-ui.alert tone="info">
            Video meetings run on the service you choose (Zoom, Google Meet or Microsoft Teams). WellNest only stores the meeting link and opens it in the staff member's own browser; it sends no client information to the video service. You need your own agreement (a BAA or DPA) with that service.
        </x-ui.alert>
        <section class="set-card">
            <div class="set-fields">
                <x-ui.field label="Default meeting link" name="default_link" id="th-default-link" help="Used for telehealth appointments that have no link of their own. Stored encrypted and never shown again; leave empty to keep the current one. Everyone who has the link enters the same room, so switch on the video service's waiting room.">
                    <x-ui.input type="url" name="default_link" id="th-default-link" value="" placeholder="{{ $hasDefaultLink ? 'A default link is saved — paste a new one to replace it' : 'https://' }}" autocomplete="off" />
                </x-ui.field>
                @if ($hasDefaultLink)
                    <div class="set-fields__full"><x-ui.toggle name="remove_default_link" id="th-remove-link" label="Remove the saved default link" :checked="false" /></div>
                @endif
                <x-ui.field label="Allowed meeting-link hosts" name="allowed_hosts" id="th-hosts" help="One host per line; *.example.com allows its sub-domains. A meeting link on any other host is refused.">
                    <x-ui.textarea name="allowed_hosts" id="th-hosts" rows="5" :value="$values['allowed_hosts']" />
                </x-ui.field>
                <x-ui.field label="Join opens (minutes before the start)" name="join_early_minutes" id="th-early" help="Staff can join from this long before a session starts until it ends (0 to {{ \App\Domain\Telehealth\TelehealthSettings::MAX_EARLY_MINUTES }}).">
                    <x-ui.input type="number" name="join_early_minutes" id="th-early" :value="$values['join_early_minutes']" min="0" :max="\App\Domain\Telehealth\TelehealthSettings::MAX_EARLY_MINUTES" />
                </x-ui.field>
                <div class="set-fields__full"><x-ui.toggle name="recording_enabled" id="th-recording" label="Allow session recordings" help="Off by default. Even when on, nothing is recorded or stored unless the client's consent is recorded for that session." :checked="$values['recording_enabled']" /></div>
                <div class="set-fields__full"><x-ui.toggle name="ai_transcripts_enabled" id="th-ai" label="Allow AI transcripts" help="Off by default. An AI transcript is always a draft until a clinician reviews it. Turn this on only after your agreement with an AI vendor is in place." :checked="$values['ai_transcripts_enabled']" /></div>
            </div>
        </section>
        <div class="form-actions"><x-ui.button type="submit">Save settings</x-ui.button></div>
    </form>
    @include('app.settings.partials.close')
</x-layouts.app>
