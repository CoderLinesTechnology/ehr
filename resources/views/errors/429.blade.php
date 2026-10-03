@php
    $retry = ($exception ?? null) instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
        ? ($exception->getHeaders()['Retry-After'] ?? null)
        : null;
    $retry = is_numeric($retry) && (int) $retry > 0 ? (int) $retry : null;
@endphp
<x-layouts.bare title="Too many requests" width="sm">
    <x-ui.status-page
        code="429"
        icon="clock"
        title="Too many requests"
        :message="'You have made a lot of requests in a short time. '.($retry ? 'Please wait about '.$retry.' '.($retry === 1 ? 'second' : 'seconds').' and try again.' : 'Please wait a moment and try again.')">
        <x-ui.button variant="secondary" data-history-back hidden>Go back</x-ui.button>
        <x-ui.button :href="url('/')">Go to home</x-ui.button>
    </x-ui.status-page>
</x-layouts.bare>
