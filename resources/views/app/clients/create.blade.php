<x-layouts.app title="Add client">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/clients.css') }}?v={{ filemtime(public_path('css/screens/clients.css')) }}">
    @endpush
    <x-slot:breadcrumbs><x-ui.breadcrumbs :items="[['label' => 'Clients', 'url' => route('app.clients.index')], ['label' => 'Add client']]" /></x-slot:breadcrumbs>
    <x-ui.page-header title="Add client" description="Register a new client. Only the name is required unless your organization asks for more." icon="user-plus" />

    <form method="POST" action="{{ route('app.clients.store') }}" class="form" data-submit-once novalidate>
        @csrf
        @include('app.clients._form')
        <div class="form-actions client-form">
            <x-ui.button variant="secondary" :href="route('app.clients.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="plus">Add client</x-ui.button>
        </div>
    </form>
</x-layouts.app>
