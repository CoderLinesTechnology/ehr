<?php

namespace App\Domain\Identity;

use App\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The roles of an organization with how many members hold each and how many permissions each grants. */
final class RoleDirectory
{
    /**
     * Locked first, then system roles, then custom roles by name.
     *
     * @return Collection<int, Role> each with `members_count` and `permissions_count` attributes
     */
    public function overview(string $organizationId): Collection
    {
        $roles = Role::query()
            ->forOrganization($organizationId)
            ->orderByDesc('is_locked')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        $members = DB::table('membership_roles')
            ->where('organization_id', $organizationId)
            ->groupBy('role_id')
            ->selectRaw('role_id, count(*) as total')
            ->pluck('total', 'role_id');

        $permissions = DB::table('role_permissions')
            ->whereIn('role_id', $roles->pluck('id'))
            ->groupBy('role_id')
            ->selectRaw('role_id, count(*) as total')
            ->pluck('total', 'role_id');

        return $roles->each(function (Role $role) use ($members, $permissions) {
            $role->setAttribute('members_count', (int) ($members[$role->id] ?? 0));
            $role->setAttribute('permissions_count', (int) ($permissions[$role->id] ?? 0));
        });
    }
}
