{{-- The exception message of a 404 can name models and ids, so it is never shown. --}}
<x-layouts.bare title="Page not found" width="sm">
    <x-ui.status-page
        code="404"
        icon="search"
        title="We could not find that page"
        message="The page may have moved, or the link may be out of date. Check the address or head back to the start.">
        <x-ui.button :href="url('/')">Go to home</x-ui.button>
        <x-ui.button variant="secondary" data-history-back hidden>Go back</x-ui.button>
    </x-ui.status-page>
</x-layouts.bare>
