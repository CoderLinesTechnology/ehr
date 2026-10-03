@php
    $areas = ['' => 'Everything', 'organization' => 'Organization', 'location' => 'Locations', 'service' => 'Services', 'team' => 'Team', 'invitation' => 'Invitations', 'role' => 'Roles', 'settings' => 'Settings', 'client' => 'Clients', 'appointment' => 'Appointments'];
@endphp
<x-layouts.app title="Audit log">
    @include('app.settings.partials.open', ['current' => 'audit'])

    <form method="GET" action="{{ route('app.settings.audit.index') }}" class="set-toolbar" role="search">
        <div class="set-toolbar__grow"><x-ui.input type="search" name="q" :value="$q" icon="search" placeholder="Search what happened or who did it" aria-label="Search the audit log" autocomplete="off" /></div>
        <x-ui.select name="area" :options="$areas" :value="$area" data-autosubmit aria-label="Area" />
        <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
    </form>

    @if ($entries->isEmpty())
        <x-ui.empty-state icon="scroll-text" title="Nothing to show" description="Changes made in your organization appear here." />
    @else
        <section class="set-card set-card--flush" aria-label="Audit log">
            <x-ui.table label="Audit log">
                <caption class="sr-only">Activity in this organization, newest first</caption>
                <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">What</th></tr></thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td class="nowrap">{{ fmt()->dateTime($entry->occurred_at) }}</td>
                            <td>{{ $entry->actor?->name ?? $entry->actor_label ?? 'System' }}</td>
                            <td>{{ $entry->summary ?: $entry->action }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </section>
        <div class="set-pager">
            @if ($entries->onFirstPage())<span></span>@else<x-ui.button variant="secondary" size="sm" :href="$entries->previousPageUrl()">Newer</x-ui.button>@endif
            @if ($entries->hasMorePages())<x-ui.button variant="secondary" size="sm" :href="$entries->nextPageUrl()">Older</x-ui.button>@endif
        </div>
    @endif
    @include('app.settings.partials.close')
</x-layouts.app>
