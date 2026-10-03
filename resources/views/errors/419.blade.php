<x-layouts.bare title="Page expired" width="sm">
    <x-ui.status-page
        code="419"
        icon="clock"
        title="This page has expired"
        message="For your security the page timed out before it was submitted. Go back, refresh the page and try again. Nothing was saved.">
        <x-ui.button variant="secondary" data-history-back hidden>Go back</x-ui.button>
        <x-ui.button :href="url('/')">Go to home</x-ui.button>
    </x-ui.status-page>
</x-layouts.bare>
