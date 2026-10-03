<x-layouts.platform title="Audit log">
    @include('platform.partials.assets')
    <x-ui.page-header title="Audit log" description="Platform actions and system events. Activity inside organizations is never part of this log." />

    <div class="pf-stack">
        <x-ui.card :padded="false">
            <form method="GET" action="{{ route('platform.audit.index') }}" class="pf-filters" role="search" aria-label="Audit log filters">
                <x-ui.field label="Action starts with" name="action"><x-ui.input name="action" :value="$filters['action']" placeholder="e.g. subscription." maxlength="100" autocomplete="off" /></x-ui.field>
                <x-ui.field label="Actor email" name="actor"><x-ui.input name="actor" :value="$filters['actor']" placeholder="Email or the start of one" maxlength="254" autocomplete="off" /></x-ui.field>
                <x-ui.field label="Source" name="context"><x-ui.select name="context" placeholder="Platform and system" :value="$filters['context']" :options="['platform' => 'Platform actions', 'system' => 'System events']" /></x-ui.field>
                <x-ui.field label="From" name="from"><x-ui.input type="date" name="from" :value="$filters['from']" /></x-ui.field>
                <x-ui.field label="To" name="to"><x-ui.input type="date" name="to" :value="$filters['to']" /></x-ui.field>
                <div class="pf-filters__actions">
                    <x-ui.button type="submit" icon="search">Filter</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('platform.audit.index')">Clear</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card :padded="false">
            @if ($entries->isEmpty())
                <x-ui.empty-state icon="history" title="No entries match" description="Try widening the dates or clearing a filter." />
            @else
                <x-ui.table label="Audit log entries">
                    <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">What</th><th scope="col">Organization</th></tr></thead>
                    <tbody>
                        @foreach ($entries as $e)
                            <tr>
                                <td class="tabular nowrap">{{ fmt()->dateTime($e->occurredAt, $timezone) }}</td>
                                <td>{{ $e->actorLabel ?? 'System' }}@if ($e->ip)<span class="pf-cell-sub mono">{{ $e->ip }}</span>@endif</td>
                                <td>
                                    <span class="mono text-sm">{{ $e->action }}</span>
                                    <x-ui.badge :tone="$e->context === 'platform' ? 'info' : 'neutral'">{{ $e->context === 'platform' ? 'Platform' : 'System' }}</x-ui.badge>
                                    @if (filled($e->summary))<span class="pf-cell-sub">{{ $e->summary }}</span>@endif
                                    @if ($e->before !== null || $e->after !== null || $e->metadata)
                                        <details class="pf-details">
                                            <summary>Details</summary>
                                            <div class="pf-diff">
                                                @if ($e->before !== null)<div><h4>Before</h4><pre>{{ json_encode($e->before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></div>@endif
                                                @if ($e->after !== null)<div><h4>After</h4><pre>{{ json_encode($e->after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></div>@endif
                                                @if ($e->metadata)<div><h4>Context</h4><pre>{{ json_encode($e->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></div>@endif
                                            </div>
                                        </details>
                                    @endif
                                </td>
                                <td>
                                    @if ($e->organizationName)
                                        @can('platform.organizations.view')<a href="{{ route('platform.organizations.show', $e->organizationSlug) }}">{{ $e->organizationName }}</a>@else{{ $e->organizationName }}@endcan
                                    @else
                                        <span class="text-muted">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>

        <div class="pf-pager">
            @if ($entries->onFirstPage())<span></span>@else<x-ui.button variant="secondary" size="sm" :href="$entries->previousPageUrl()">Newer</x-ui.button>@endif
            @if ($entries->hasMorePages())<x-ui.button variant="secondary" size="sm" :href="$entries->nextPageUrl()">Older</x-ui.button>@endif
        </div>
    </div>
</x-layouts.platform>
