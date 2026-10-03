<section class="sg-block" id="feedback">
    <h2>Feedback</h2>
    <p class="sg-lead">Alerts, flash messages, empty states and progress. Problems use <code>role="alert"</code>; confirmations and hints use <code>role="status"</code>.</p>

    <div class="sg-demo stack">
        <x-ui.alert tone="info" title="Heads up">Info alerts explain something without asking for action.</x-ui.alert>
        <x-ui.alert tone="success" :dismissible="true">Success alert with only a body. It can be dismissed once JavaScript has loaded.</x-ui.alert>
        <x-ui.alert tone="warning" title="Check before you continue">Warnings describe a risk the user can still avoid.</x-ui.alert>
        <x-ui.alert tone="danger" title="Something needs fixing">Errors say what went wrong and what to do about it.</x-ui.alert>
    </div>

    <div class="sg-demo" id="flash">
        <p class="sg-label">Flash messages</p>
        <p class="text-muted text-sm">Each button posts to a sample route, which redirects back with a session flash. Success and info tidy themselves away after a few seconds (paused on hover and focus); problems stay until dismissed. Without JavaScript they simply stay.</p>
        <form method="POST" action="{{ route('dev.styleguide.flash') }}" class="cluster">
            @csrf
            @foreach (['success', 'error', 'warning', 'info'] as $kind)
                <x-ui.button type="submit" variant="secondary" size="sm" name="kind" :value="$kind">Flash {{ $kind }}</x-ui.button>
            @endforeach
        </form>
        <form method="POST" action="{{ route('dev.styleguide.flash') }}" class="cluster" style="margin-top: .75rem">
            @csrf
            @foreach (['verification-link-sent', 'two-factor-authentication-confirmed', 'recovery-codes-generated', 'password-updated', 'profile-information-updated'] as $kind)
                <x-ui.button type="submit" variant="ghost" size="sm" name="kind" :value="$kind">{{ $kind }}</x-ui.button>
            @endforeach
        </form>
    </div>

    <div class="sg-demo">
        <p class="sg-label">Empty state</p>
        <x-ui.empty-state icon="users" title="No clients yet" description="Add your first client to start scheduling appointments and keeping notes.">
            <x-slot:actions>
                <x-ui.button icon="user-plus">Add client</x-ui.button>
                <x-ui.button variant="secondary" icon="upload">Import</x-ui.button>
            </x-slot:actions>
        </x-ui.empty-state>
    </div>

    <div class="sg-demo stack">
        <p class="sg-label">Progress, spinner and checklist</p>
        <x-ui.progress :value="3" :max="7" label="Setup progress" />
        <div class="cluster">
            <x-ui.spinner label="Loading clients" />
            <span class="text-muted text-sm">Spinner (a gentle pulse instead of rotation when reduced motion is on)</span>
        </div>
        <ul class="checklist" role="list">
            <x-ui.checklist-item :done="true" title="Create your organization" description="Name, timezone and currency." />
            <x-ui.checklist-item :done="true" title="Add a location" description="Where clients are seen." href="#feedback" />
            <x-ui.checklist-item title="Invite your team" description="Clinicians and front-desk staff." href="#feedback" />
            <x-ui.checklist-item title="Set weekly availability" description="So clients can be booked." href="#feedback" />
        </ul>
    </div>
</section>
