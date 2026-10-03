<x-layouts.platform title="Plans & features">
    @include('platform.partials.assets')
    <x-ui.page-header title="Plans & features" description="What each plan costs and includes. Editing a plan changes every organization on it at once.">
        <x-slot:actions>
            <x-ui.button :href="route('platform.plans.create')" icon="plus">New plan</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padded="false">
        @if ($plans->isEmpty())
            <x-ui.empty-state icon="layers" title="No plans yet" description="Create a plan before organizations can subscribe." />
        @else
            <x-ui.table label="Plans">
                <thead>
                    <tr>
                        <th scope="col">Plan</th>
                        <th scope="col">Price</th>
                        <th scope="col">Trial</th>
                        <th scope="col" class="table__num">Organizations</th>
                        <th scope="col">Availability</th>
                        <th scope="col" class="table__actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($plans as $plan)
                        <tr>
                            <td><a href="{{ route('platform.plans.edit', $plan) }}" class="fw-medium">{{ $plan->name }}</a><span class="pf-cell-sub mono">{{ $plan->key }}</span></td>
                            <td class="tabular nowrap">{{ fmt()->money($plan->price_minor, $plan->currency) }} / {{ $plan->billing_interval }}</td>
                            <td class="tabular">{{ $plan->trial_days > 0 ? $plan->trial_days.' days' : 'None' }}</td>
                            <td class="table__num tabular">{{ number_format($plan->live_subscriptions_count) }}</td>
                            <td>
                                <div class="pf-plan-flag">
                                    <x-ui.badge :tone="$plan->is_active ? 'success' : 'neutral'">{{ $plan->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                                    <x-ui.badge :tone="$plan->is_public ? 'info' : 'neutral'">{{ $plan->is_public ? 'Public' : 'Hidden' }}</x-ui.badge>
                                </div>
                            </td>
                            <td class="table__actions"><x-ui.button variant="secondary" size="sm" icon="pencil" :href="route('platform.plans.edit', $plan)">Edit</x-ui.button></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
    <p class="pf-note" style="margin-top: 12px">Plans are never deleted: deactivate one to stop new organizations choosing it. Organizations already on it keep it.</p>
</x-layouts.platform>
