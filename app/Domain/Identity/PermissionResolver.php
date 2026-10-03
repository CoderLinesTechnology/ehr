<?php

namespace App\Domain\Identity;

use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the permission keys a membership (organization scope) or a user
 * (platform scope) holds. One query each, memoised for the request.
 */
final class PermissionResolver
{
    /** @var array<string, array<string, true>> */
    private array $memberships = [];

    /** @var array<string, array<string, true>> */
    private array $platformUsers = [];

    public function membershipHas(OrganizationMembership $membership, string $permission): bool
    {
        return isset($this->membershipPermissions($membership)[$permission]);
    }

    /** @return array<string, true> */
    public function membershipPermissions(OrganizationMembership $membership): array
    {
        return $this->memberships[$membership->id] ??= array_fill_keys(
            DB::table('membership_roles')
                ->join('role_permissions', 'role_permissions.role_id', '=', 'membership_roles.role_id')
                ->where('membership_roles.membership_id', $membership->id)
                ->where('membership_roles.organization_id', $membership->organization_id)
                ->where('role_permissions.scope', PermissionRegistry::SCOPE_ORGANIZATION)
                ->distinct()
                ->pluck('role_permissions.permission_key')
                ->all(),
            true,
        );
    }

    public function platformHas(User $user, string $permission): bool
    {
        return isset($this->platformPermissions($user)[$permission]);
    }

    /** @return array<string, true> */
    public function platformPermissions(User $user): array
    {
        return $this->platformUsers[$user->id] ??= array_fill_keys(
            DB::table('platform_user_roles')
                ->join('role_permissions', 'role_permissions.role_id', '=', 'platform_user_roles.role_id')
                ->where('platform_user_roles.user_id', $user->id)
                ->where('role_permissions.scope', PermissionRegistry::SCOPE_PLATFORM)
                ->distinct()
                ->pluck('role_permissions.permission_key')
                ->all(),
            true,
        );
    }

    public function isPlatformUser(User $user): bool
    {
        return $this->platformPermissions($user) !== [];
    }

    /** Call after changing role assignments or grants. */
    public function flush(): void
    {
        $this->memberships = [];
        $this->platformUsers = [];
    }
}
