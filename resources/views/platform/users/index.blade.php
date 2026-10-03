<x-layouts.platform title="Users">
    @include('platform.partials.assets')
    <x-ui.page-header title="Users" description="Every account across all organizations. Open one to see which practices it belongs to and whether it can sign in." />

    <div class="pf-stack">
        <div class="cluster">
            <x-ui.search-input name="q" :value="$filters['q']" placeholder="Search by name or email" :keep="['status', 'staff', 'sort', 'direction']" label="Search users" />
            <x-ui.filter-bar :action="route('platform.users.index')" label="User filters">
                <input type="hidden" name="q" value="{{ $filters['q'] }}">
                <x-ui.select name="status" placeholder="Any status" data-autosubmit :value="$filters['status']" aria-label="Filter by status" :options="['active' => 'Active', 'disabled' => 'Disabled']" />
                <x-ui.select name="staff" placeholder="All accounts" data-autosubmit :value="$filters['staff'] ? '1' : null" aria-label="Filter by platform access" :options="['1' => 'Platform staff only']" />
            </x-ui.filter-bar>
        </div>

        <x-ui.card :padded="false">
            @if ($users->isEmpty())
                <x-ui.empty-state icon="users" title="No users match" description="Try a different search or clear the filters.">
                    <x-slot:actions><x-ui.button variant="secondary" :href="route('platform.users.index')">Clear filters</x-ui.button></x-slot:actions>
                </x-ui.empty-state>
            @else
                <x-ui.table label="Users">
                    <thead>
                        <tr>
                            <x-ui.sort-link :th="true" column="name" label="Name" />
                            <th scope="col">Status</th>
                            <th scope="col" class="table__num">Organizations</th>
                            <x-ui.sort-link :th="true" column="last_login_at" label="Last sign-in" />
                            <x-ui.sort-link :th="true" column="created_at" label="Joined" />
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $u)
                            <tr>
                                <td>
                                    <a href="{{ route('platform.users.show', $u->id) }}" class="fw-medium">{{ $u->name }}</a>
                                    <span class="pf-cell-sub">{{ $u->email }}</span>
                                </td>
                                <td>
                                    <div class="pf-plan-flag">
                                        <x-ui.badge :tone="$u->disabled ? 'danger' : 'success'">{{ $u->disabled ? 'Disabled' : 'Active' }}</x-ui.badge>
                                        @unless ($u->emailVerified)<x-ui.badge tone="warning">Unverified</x-ui.badge>@endunless
                                        @foreach ($u->platformRoles as $role)<x-ui.badge tone="purple">{{ $role }}</x-ui.badge>@endforeach
                                    </div>
                                </td>
                                <td class="table__num tabular">{{ number_format($u->organizationsCount) }}</td>
                                <td class="tabular nowrap">{{ $u->lastLoginAt ? fmt()->localDate($u->lastLoginAt, $timezone) : 'Never' }}</td>
                                <td class="tabular nowrap">{{ fmt()->localDate($u->createdAt, $timezone) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>

        {{ $users->links() }}
    </div>
</x-layouts.platform>
