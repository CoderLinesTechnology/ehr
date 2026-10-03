{{-- Header shared by every screen of one client's profile. Needs: $client (primaryClinician.user loaded), $tab, $tabs, $can, $bookUrl. --}}
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/screens/clients.css') }}?v={{ filemtime(public_path('css/screens/clients.css')) }}">
@endpush

<header class="profile-head">
    <div class="profile-head__lead">
        <x-ui.avatar :name="$client->displayName()" class="profile-head__avatar" :decorative="true" />
        <div>
            <h1 class="profile-head__title">{{ $client->displayName() }}</h1>
            <div class="profile-head__meta">
                <span class="num">{{ $client->formattedNumber() }}</span>
                <x-ui.badge :tone="['active' => 'success', 'pending' => 'pending', 'inactive' => 'neutral', 'archived' => 'neutral'][$client->status->value]">{{ $client->status->label() }}</x-ui.badge>
                @if ($client->isDemo())<x-ui.badge tone="demo">Demo</x-ui.badge>@endif
                @if ($client->age() !== null)<span>{{ $client->age() }} {{ \Illuminate\Support\Str::plural('year', $client->age()) }} old</span>@endif
                @if (filled($client->pronouns))<span>{{ $client->pronouns }}</span>@endif
                <span>Primary clinician: {{ $client->primaryClinician?->displayName() ?? 'not assigned' }}</span>
            </div>
        </div>
    </div>
    <div class="profile-head__actions">
        @if ($can['update'])
            <x-ui.button variant="secondary" icon="pencil" :href="route('app.clients.edit', ['client' => $client])">Edit</x-ui.button>
        @endif
        @if ($bookUrl)
            <x-ui.button icon="calendar-plus" :href="$bookUrl">Book appointment</x-ui.button>
        @endif
    </div>
</header>

<x-ui.tabs :tabs="$tabs" label="Client sections" class="profile-tabs" />
