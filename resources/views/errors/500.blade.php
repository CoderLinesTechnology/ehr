{{-- Deliberately free of exception detail, request data and anything that touches the database. --}}
<x-layouts.bare title="Something went wrong" width="sm">
    <x-ui.status-page
        code="500"
        icon="alert-triangle"
        title="Something went wrong on our side"
        message="An unexpected error stopped this page from loading. Please try again in a moment. If it keeps happening, contact your administrator.">
        <x-ui.button :href="url('/')">Go to home</x-ui.button>
        <x-ui.button variant="secondary" data-history-back hidden>Go back</x-ui.button>
    </x-ui.status-page>
</x-layouts.bare>
