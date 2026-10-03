{{-- Also pre-rendered by "artisan down --render", so it must not rely on a session, a database or the shell composer. --}}
<x-layouts.bare title="Back soon" width="sm">
    <x-ui.status-page
        code="503"
        icon="refresh"
        title="We will be right back"
        message="The service is briefly unavailable while we carry out maintenance. Please try again in a few minutes.">
        <x-ui.button :href="url('/')" icon="refresh">Try again</x-ui.button>
    </x-ui.status-page>
</x-layouts.bare>
