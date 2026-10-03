<x-layouts.app :title="'Edit '.$client->displayName()">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/clients.css') }}?v={{ filemtime(public_path('css/screens/clients.css')) }}">
    @endpush
    <x-slot:breadcrumbs><x-ui.breadcrumbs :items="[['label' => 'Clients', 'url' => route('app.clients.index')], ['label' => $client->displayName(), 'url' => route('app.clients.show', ['client' => $client])], ['label' => 'Edit']]" /></x-slot:breadcrumbs>
    <x-ui.page-header :title="'Edit '.$client->displayName()" :description="$client->formattedNumber()" icon="pencil" />

    <form method="POST" action="{{ route('app.clients.update', ['client' => $client]) }}" class="form" data-submit-once novalidate>
        @csrf
        @method('PUT')
        @include('app.clients._form')
        <div class="form-actions client-form">
            <x-ui.button variant="secondary" :href="route('app.clients.show', ['client' => $client])">Cancel</x-ui.button>
            <x-ui.button type="submit">Save changes</x-ui.button>
        </div>
    </form>
</x-layouts.app>
