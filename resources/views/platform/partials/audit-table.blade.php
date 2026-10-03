{{-- A compact list of audit entries: when, who, what. Used on the dashboard and on organization and user pages. --}}
@if ($entries->isEmpty())
    <x-ui.empty-state icon="history" title="Nothing recorded yet" description="Platform actions such as status changes and plan changes will be listed here." />
@else
    <x-ui.table :compact="true" label="Recent platform activity">
        <thead>
            <tr>
                <th scope="col">When</th>
                <th scope="col">Who</th>
                <th scope="col">What</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($entries as $entry)
                <tr>
                    <td class="tabular nowrap">{{ fmt()->dateTime($entry->occurred_at, $timezone) }}</td>
                    <td>{{ $entry->actor_label ?? 'System' }}</td>
                    <td>
                        <span class="mono text-sm">{{ $entry->action }}</span>
                        @if (filled($entry->summary))<div class="text-muted text-sm">{{ $entry->summary }}</div>@endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table>
@endif
