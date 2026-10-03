<x-layouts.platform title="Administrators">
    @include('platform.partials.assets')
    <x-ui.page-header title="Administrators" description="Everyone who can open this console. Roles are granted to an existing account; the person sets up two-factor authentication the next time they open it." />

    <div class="pf-stack">
        <x-ui.card title="Grant a role" description="The account must already exist and have a verified email address.">
            <form method="POST" action="{{ route('platform.admins.store') }}" class="form" data-submit-once>
                @csrf
                <div class="pf-grid-form">
                    <x-ui.field label="Email address" name="email" :required="true"><x-ui.input type="email" name="email" required maxlength="254" autocomplete="off" /></x-ui.field>
                    <x-ui.field label="Role" name="role" :required="true">
                        <x-ui.select name="role" placeholder="Choose a role" required :options="$roles->mapWithKeys(fn ($r) => [$r->key => $r->name])->all()" />
                    </x-ui.field>
                    <x-ui.field label="Reason" name="reason" :required="true" help="Kept in the audit log."><x-ui.input name="reason" required maxlength="500" /></x-ui.field>
                </div>
                <div class="pf-note">
                    @foreach ($roles as $r)<div><strong>{{ $r->name }}</strong>: {{ $r->description }}</div>@endforeach
                </div>
                <div class="form-actions"><x-ui.button type="submit" icon="shield-check">Grant role</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card title="Platform administrators" :padded="false">
            <x-ui.table label="Platform administrators">
                <thead><tr><th scope="col">Person</th><th scope="col">Roles</th><th scope="col">Two-factor</th><th scope="col">Last sign-in</th></tr></thead>
                <tbody>
                    @foreach ($admins as $admin)
                        <tr>
                            <td>
                                <a href="{{ route('platform.users.show', $admin->id) }}" class="fw-medium">{{ $admin->name }}</a>@if ($admin->id === $me) <x-ui.badge tone="outline">You</x-ui.badge>@endif
                                <span class="pf-cell-sub">{{ $admin->email }}</span>
                                @if ($admin->disabled)<x-ui.badge tone="danger">Disabled</x-ui.badge>@endif
                            </td>
                            <td>
                                <div class="pf-roles">
                                    @foreach ($admin->roles as $role)
                                        <div class="pf-role">
                                            <div class="pf-role__text">
                                                <strong>{{ $role['name'] }}</strong>
                                                <span class="pf-cell-sub">
                                                    @if ($role['granted_at']){{ fmt()->localDate($role['granted_at'], $timezone) }}@endif
                                                    @if ($role['granted_by']) by {{ $role['granted_by'] }}@endif
                                                </span>
                                            </div>
                                            <x-ui.confirm-form :action="route('platform.admins.destroy', [$admin->id, $role['key']])" method="DELETE"
                                                :title="'Remove '.$role['name'].' from '.$admin->name.'?'" message="They lose this access straight away. The last Super Admin cannot be removed."
                                                button-label="Revoke" button-size="sm" button-variant="neutral" confirm-label="Revoke role"
                                                reason-field="reason" :reason-required="true" :bag="\App\Http\Requests\Platform\RevokePlatformRoleRequest::bagFor($admin->id, $role['key'])" />
                                        </div>
                                    @endforeach
                                </div>
                            </td>
                            <td><x-ui.badge :tone="$admin->twoFactorConfirmed ? 'success' : 'warning'">{{ $admin->twoFactorConfirmed ? 'Confirmed' : 'Not set up' }}</x-ui.badge></td>
                            <td class="tabular nowrap">{{ $admin->lastLoginAt ? fmt()->localDate($admin->lastLoginAt, $timezone) : 'Never' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </x-ui.card>
    </div>
</x-layouts.platform>
