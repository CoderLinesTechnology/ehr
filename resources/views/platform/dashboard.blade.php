<x-layouts.platform title="Dashboard">
    @php
        $orgs = $data['organizations'];
        $users = $data['users'];
        $days = $data['period_days'];
    @endphp

    <x-ui.page-header title="Platform dashboard" description="How the platform is doing. These are counts only: no client or appointment details are shown on any platform screen.">
        <x-slot:actions>
            <x-ui.filter-bar :action="route('platform.dashboard')" label="Reporting period">
                <x-ui.select name="period" :value="$days" data-autosubmit aria-label="Reporting period" :options="collect($periods)->mapWithKeys(fn ($p) => [$p => 'Last '.$p.' days'])->all()" />
            </x-ui.filter-bar>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="stack stack--lg">
        <div class="grid-4">
            <x-ui.stat label="Organizations" :value="number_format($orgs['total'])" :hint="number_format($orgs['new_in_period']).' new in the last '.$days.' days'" icon="building" :href="route('platform.organizations.index')" />
            <x-ui.stat label="Users" :value="number_format($users['total'])" :hint="number_format($users['active_30d']).' signed in during the last 30 days · '.number_format($users['new_in_period']).' new in the last '.$days.' days'" icon="users" :href="route('platform.users.index')" />
            <x-ui.stat label="Active clients" :value="number_format($data['live_active_clients'])" hint="Live records across all organizations. Demo data is not counted." icon="user" />
            <x-ui.stat label="Appointments" :value="number_format($data['appointments_in_period'])" :hint="'Live appointments that started in the last '.$days.' days'" icon="calendar" />
            <x-ui.stat label="Trials ending soon" :value="number_format($data['trials_ending']['count'])" hint="Trials that end within the next 7 days" icon="clock" />
            <x-ui.stat label="Failed queue jobs" :value="number_format($data['failed_jobs'])" :hint="$data['failed_jobs'] > 0 ? 'Needs attention: failed background work is waiting in the failed jobs table.' : 'Nothing is stuck.'" icon="alert-triangle" />
        </div>

        <div class="grid-2">
            <x-ui.card title="Organizations by status" :padded="false">
                <x-ui.table :compact="true" label="Organizations by status">
                    <thead><tr><th scope="col">Status</th><th scope="col" class="table__num">Organizations</th></tr></thead>
                    <tbody>
                        @foreach (\App\Domain\Platform\OrganizationStatus::cases() as $status)
                            <tr>
                                <td><x-ui.badge :tone="$status->tone()">{{ $status->label() }}</x-ui.badge></td>
                                <td class="table__num tabular">
                                    @if ($orgs['by_status'][$status->value] > 0)
                                        <a href="{{ route('platform.organizations.index', ['status' => $status->value]) }}">{{ number_format($orgs['by_status'][$status->value]) }}</a>
                                    @else
                                        0
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Live subscriptions by plan" :padded="false">
                @if ($data['subscriptions_by_plan'] === [])
                    <x-ui.empty-state icon="layers" title="No live subscriptions yet" description="Subscriptions appear here once an organization is created or a subscription is started." />
                @else
                    <x-ui.table :compact="true" label="Live subscriptions by plan">
                        <thead><tr><th scope="col">Plan</th><th scope="col" class="table__num">Organizations</th></tr></thead>
                        <tbody>
                            @foreach ($data['subscriptions_by_plan'] as $plan)
                                <tr>
                                    <td><a href="{{ route('platform.organizations.index', ['plan' => $plan['key']]) }}">{{ $plan['name'] }}</a></td>
                                    <td class="table__num tabular">{{ number_format($plan['count']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                @endif
            </x-ui.card>
        </div>

        <x-ui.card title="Trials ending in the next 7 days" :padded="false">
            @if ($data['trials_ending']['next'] === [])
                <x-ui.empty-state icon="clock" title="No trials are about to end" description="Organizations on a trial show up here in the week before it ends, so someone can follow up." />
            @else
                <x-ui.table :compact="true" label="Trials ending soon">
                    <thead><tr><th scope="col">Organization</th><th scope="col">Trial ends</th></tr></thead>
                    <tbody>
                        @foreach ($data['trials_ending']['next'] as $trial)
                            <tr>
                                <td><a href="{{ route('platform.organizations.show', $trial['slug']) }}">{{ $trial['name'] }}</a></td>
                                <td class="tabular">{{ fmt()->dateTime($trial['ends_at'], $timezone) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
                @if ($data['trials_ending']['count'] > count($data['trials_ending']['next']))
                    <p class="text-muted text-sm" style="padding: 0 1rem 1rem">Showing the first {{ count($data['trials_ending']['next']) }} of {{ $data['trials_ending']['count'] }}.</p>
                @endif
            @endif
        </x-ui.card>

        <x-ui.card title="Recent platform activity" description="The latest platform and system events. Activity inside organizations is never shown here." :padded="false">
            <x-slot:actions>
                @can('platform.audit.view')
                    <x-ui.button variant="secondary" size="sm" :href="route('platform.audit.index')">Open audit log</x-ui.button>
                @endcan
            </x-slot:actions>
            @include('platform.partials.audit-table', ['entries' => $data['recent_audit'], 'timezone' => $timezone, 'compact' => true])
        </x-ui.card>
    </div>
</x-layouts.platform>
