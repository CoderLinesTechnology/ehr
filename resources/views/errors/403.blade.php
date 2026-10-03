@php
    // A policy can carry a deliberate, user-safe reason ("Your organization is suspended"). Framework defaults are replaced by our own wording.
    $reason = ($exception ?? null) instanceof \Throwable ? trim((string) $exception->getMessage()) : '';
    $reason = in_array($reason, ['', 'This action is unauthorized.', 'Forbidden'], true) ? null : $reason;
@endphp
<x-layouts.bare title="No access" width="sm">
    <x-ui.status-page
        code="403"
        icon="lock"
        title="You do not have access to this page"
        :message="$reason ?? 'Your account does not have permission to open this page. If you think this is a mistake, ask an administrator of your organization.'">
        <x-ui.button :href="url('/')">Go to home</x-ui.button>
        <x-ui.button variant="secondary" data-history-back hidden>Go back</x-ui.button>
    </x-ui.status-page>
</x-layouts.bare>
