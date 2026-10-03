@php
    $p = $overview->profile;
    $date = fn ($d) => $d ? fmt()->dateTime($d, $timezone) : null;
@endphp
<x-layouts.platform :title="$p['name']">
    @include('platform.partials.assets')
    <x-ui.page-header :title="$p['name']" :description="$p['email']">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs :items="[['label' => 'Users', 'url' => route('platform.users.index')], ['label' => $p['name']]]" />
        </x-slot:breadcrumbs>
        <x-slot:meta>
            <div class="pf-meta">
                <x-ui.badge :tone="$p['disabled'] ? 'danger' : 'success'" :dot="true">{{ $p['disabled'] ? 'Disabled' : 'Active' }}</x-ui.badge>
                @if (! $p['email_verified_at'])<x-ui.badge tone="warning">Email not verified</x-ui.badge>@endif
                @foreach ($overview->platformRoles as $role)<x-ui.badge tone="purple">{{ $role['name'] }}</x-ui.badge>@endforeach
            </div>
        </x-slot:meta>
        <x-slot:actions>
            @can('platform.support.act')
                @unless ($p['email_verified_at'])
                    <form method="POST" action="{{ route('platform.users.verification', $account) }}" data-submit-once>@csrf<x-ui.button type="submit" variant="secondary" icon="send">Resend verification email</x-ui.button></form>
                @endunless
            @endcan
            @can('platform.users.manage')
                @if (! $isSelf)
                    @if ($p['disabled'])
                        <x-ui.confirm-form :action="route('platform.users.enable', $account)" title="Enable this account?" message="They can sign in again straight away."
                            tone="primary" button-variant="secondary" button-label="Enable account" confirm-label="Enable account" reason-field="reason" bag="enable_user" />
                    @else
                        <x-ui.confirm-form :action="route('platform.users.disable', $account)" title="Disable this account?" message="They are signed out everywhere and cannot sign in until it is enabled again. Their records are kept."
                            button-label="Disable account" confirm-label="Disable account" reason-field="reason" :reason-required="true" bag="disable_user" />
                    @endif
                @endif
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="pf-stack">
        <div class="pf-split">
            <x-ui.card title="Organizations" description="Where this person works. Only the practice name, its status and their roles are shown." :padded="false">
                @if ($overview->memberships === [])
                    <x-ui.empty-state icon="building-2" title="Not a member of any organization" description="This account has no staff membership yet." />
                @else
                    <x-ui.table label="Organizations">
                        <thead><tr><th scope="col">Organization</th><th scope="col">Membership</th><th scope="col">Roles</th></tr></thead>
                        <tbody>
                            @foreach ($overview->memberships as $m)
                                <tr>
                                    <td>
                                        @can('platform.organizations.view')<a href="{{ route('platform.organizations.show', $m['slug']) }}" class="fw-medium">{{ $m['organization'] }}</a>@else<span class="fw-medium">{{ $m['organization'] }}</span>@endcan
                                        <span class="pf-cell-sub">{{ ucfirst($m['organization_status']) }}</span>
                                    </td>
                                    <td><x-ui.badge :tone="$m['status'] === 'active' ? 'success' : 'neutral'">{{ ucfirst($m['status']) }}</x-ui.badge></td>
                                    <td>{{ $m['roles'] === [] ? 'No role' : implode(', ', $m['roles']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                @endif
            </x-ui.card>

            <x-ui.card title="Account">
                <x-ui.dl>
                    <x-ui.dl-item label="Email verified">{{ $date($p['email_verified_at']) ?? 'Not yet' }}</x-ui.dl-item>
                    <x-ui.dl-item label="Two-factor authentication">{{ $p['two_factor_enabled'] ? 'On' : 'Off' }}</x-ui.dl-item>
                    <x-ui.dl-item label="Timezone">{{ $p['timezone'] }}</x-ui.dl-item>
                    <x-ui.dl-item label="Last sign-in">{{ $date($p['last_login_at']) ?? 'Never' }}</x-ui.dl-item>
                    <x-ui.dl-item label="Joined">{{ $date($p['created_at']) }}</x-ui.dl-item>
                </x-ui.dl>
            </x-ui.card>
        </div>

        <x-ui.card title="Recent platform activity" description="Platform actions about this account. What the person does inside an organization is never shown here." :padded="false">
            @include('platform.partials.audit-table', ['entries' => $overview->recentAudit, 'timezone' => $timezone])
        </x-ui.card>
    </div>
</x-layouts.platform>
