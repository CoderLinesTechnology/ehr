<x-layouts.app title="{{ $client->displayName() }} · Edit contact">
    <x-slot:breadcrumbs><x-ui.breadcrumbs :items="[['label' => 'Clients', 'url' => route('app.clients.index')], ['label' => $client->displayName()]]" /></x-slot:breadcrumbs>
    @include('app.clients._header')

    <x-ui.card title="Edit contact" class="client-form">
        <form method="POST" action="{{ route('app.clients.contacts.update', ['client' => $client, 'contact' => $contact]) }}" class="form" data-submit-once novalidate>
            @csrf
            @method('PUT')
            @include('app.clients._contact-fields', ['contact' => $contact])
            <div class="form-actions form-actions--divided">
                <x-ui.button variant="secondary" :href="route('app.clients.contacts.index', ['client' => $client])">Cancel</x-ui.button>
                <x-ui.button type="submit">Save contact</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</x-layouts.app>
