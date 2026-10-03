{{-- Header shared by every screen of one client's profile. Needs: $client (primaryClinician.user loaded), $tab, $tabs, $can, $bookUrl. --}}
@php
    $archived = $client->status === \App\Domain\Clients\ClientStatus::Archived;
@endphp
<x-ui.page-header :title="$client->displayName()">
    <x-slot:breadcrumbs>
        <x-ui.breadcrumbs :items="[['label' => 'Clients', 'url' => route('app.clients.index')], ['label' => $client->displayName()]]" />
    </x-slot:breadcrumbs>

    <x-slot:meta>
        <span class="tabular fw-medium">{{ $client->formattedNumber() }}</span>
        <x-ui.badge :tone="$client->status->tone()">{{ $client->status->label() }}</x-ui.badge>
        @if ($client->isDemo())<x-ui.badge tone="demo">Demo</x-ui.badge>@endif
        @if ($client->age() !== null)<span class="text-muted">{{ $client->age() }} {{ \Illuminate\Support\Str::plural('year', $client->age()) }} old</span>@endif
        @if (filled($client->pronouns))<span class="text-muted">{{ $client->pronouns }}</span>@endif
        <span class="text-muted">Primary clinician: {{ $client->primaryClinician?->displayName() ?? 'not assigned' }}</span>
    </x-slot:meta>

    <x-slot:actions>
        @if ($can['update'])
            <x-ui.button variant="secondary" icon="edit" :href="route('app.clients.edit', ['client' => $client])">Edit</x-ui.button>
        @endif
        @if ($bookUrl)
            <x-ui.button icon="calendar-plus" :href="$bookUrl">Book appointment</x-ui.button>
        @endif
        @if ($can['archive'])
            @if ($archived)
                <x-ui.confirm-form
                    :action="route('app.clients.status', ['client' => $client])"
                    title="Restore this client?"
                    message="They will appear in the client list again and count towards your plan's active-client limit."
                    confirm-label="Restore client"
                    tone="primary"
                    button-label="Restore"
                    button-icon="refresh"
                    reason-field="reason"
                    reason-label="Reason (optional)"
                    bag="status_active">
                    <input type="hidden" name="status" value="active">
                </x-ui.confirm-form>
            @else
                <x-ui.confirm-form
                    :action="route('app.clients.status', ['client' => $client])"
                    title="Archive this client?"
                    message="Archived clients are hidden from everyday lists and no longer count towards your plan's limit. Their record is kept, and you can restore them later."
                    confirm-label="Archive client"
                    button-label="Archive"
                    button-variant="secondary"
                    button-icon="archive"
                    reason-field="reason"
                    reason-label="Why is this client being archived?"
                    :reason-required="true"
                    bag="status_archived">
                    <input type="hidden" name="status" value="archived">
                </x-ui.confirm-form>
            @endif
        @endif
    </x-slot:actions>
</x-ui.page-header>

<x-ui.tabs :tabs="$tabs" label="Client sections" />
