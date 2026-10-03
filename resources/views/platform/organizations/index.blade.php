<x-layouts.platform title="Organizations">
    @include('platform.partials.assets')
    <x-ui.page-header title="Organizations" description="Every practice on the platform. Open one to manage its status, plan and entitlements.">
        <x-slot:actions>
            @can('platform.organizations.manage')
                <x-ui.button :href="route('platform.organizations.create')" icon="plus">New organization</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="pf-stack">
        <div class="cluster">
            <x-ui.search-input name="q" :value="$filters['q']" placeholder="Search by name, address or email" :keep="['status', 'plan', 'sort', 'direction']" label="Search organizations" />
            <x-ui.filter-bar :action="route('platform.organizations.index')" label="Organization filters">
                <input type="hidden" name="q" value="{{ $filters['q'] }}">
                @if (request()->query('sort') && request()->query('direction'))
                    <input type="hidden" name="sort" value="{{ request()->query('sort') }}">
                    <input type="hidden" name="direction" value="{{ request()->query('direction') }}">
                @endif
                <x-ui.select name="status" placeholder="Any status" data-autosubmit :value="$filters['status']" aria-label="Filter by status"
                    :options="collect($statuses)->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" />
                <x-ui.select name="plan" placeholder="Any plan" data-autosubmit :value="$filters['plan']" aria-label="Filter by plan"
                    :options="$plans->mapWithKeys(fn ($p) => [$p->key => $p->name])->put('none', 'No live subscription')->all()" />
            </x-ui.filter-bar>
        </div>

        <x-ui.card :padded="false">
            @if ($organizations->isEmpty())
                <x-ui.empty-state icon="building-2" title="No organizations match" description="Try a different search or clear the filters.">
                    <x-slot:actions>
                        <x-ui.button variant="secondary" :href="route('platform.organizations.index')">Clear filters</x-ui.button>
                    </x-slot:actions>
                </x-ui.empty-state>
            @else
                <x-ui.table label="Organizations">
                    <thead>
                        <tr>
                            <x-ui.sort-link :th="true" column="name" label="Organization" />
                            <th scope="col">Status</th>
                            <th scope="col">Plan</th>
                            <th scope="col" class="table__num">Staff</th>
                            <x-ui.sort-link :th="true" column="created_at" label="Created" />
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($organizations as $organization)
                            <tr>
                                <td>
                                    <a href="{{ route('platform.organizations.show', $organization->slug) }}" class="fw-medium">{{ $organization->name }}</a>
                                    <span class="pf-cell-sub mono">{{ $organization->slug }}</span>
                                </td>
                                <td><x-ui.badge :tone="$organization->status->tone()">{{ $organization->status->label() }}</x-ui.badge></td>
                                <td>
                                    @if ($organization->planName)
                                        {{ $organization->planName }}
                                        @if ($organization->subscriptionStatus !== \App\Domain\Saas\SubscriptionStatus::Active)
                                            <span class="pf-cell-sub">{{ $organization->subscriptionStatus->label() }}</span>
                                        @endif
                                    @else
                                        <span class="text-muted">None</span>
                                    @endif
                                </td>
                                <td class="table__num tabular">{{ number_format($organization->staffCount) }}</td>
                                <td class="tabular nowrap">{{ fmt()->localDate($organization->createdAt, $timezone) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>

        {{ $organizations->links() }}
    </div>
</x-layouts.platform>
