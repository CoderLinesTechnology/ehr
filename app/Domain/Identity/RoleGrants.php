<?php

namespace App\Domain\Identity;

use App\Domain\Shared\DomainException;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes the permission set of an organization role. Every write path
 * funnels its input through normalise(): only catalogued ORGANIZATION permissions
 * get through, so a platform.* key (or a made-up one) is refused before it can reach
 * the database, which refuses platform.* on an organization role as a second wall.
 */
final class RoleGrants
{
    /**
     * @param  array<int, mixed>  $keys
     * @return list<string> sorted, unique, organization-scope keys
     *
     * @throws DomainException
     */
    public static function normalise(array $keys): array
    {
        $keys = array_values(array_unique(array_map('strval', array_filter($keys, 'is_scalar'))));

        foreach ($keys as $key) {
            if (PermissionRegistry::isPlatform($key)) {
                throw new DomainException('Platform permissions can never be given to an organization role.', 'platform_permission', 'permissions');
            }
            if (! PermissionRegistry::exists($key)) {
                throw new DomainException('One of the selected permissions does not exist.', 'unknown_permission', 'permissions');
            }
        }

        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    public static function current(Role $role): array
    {
        $keys = DB::table('role_permissions')->where('role_id', $role->id)->pluck('permission_key')->all();
        sort($keys);

        return $keys;
    }

    /** @param list<string> $keys */
    public static function grant(Role $role, array $keys): void
    {
        SyncPermissionCatalogue::grant($role, $keys);
    }

    /** @param list<string> $keys */
    public static function revoke(Role $role, array $keys): void
    {
        if ($keys !== []) {
            DB::table('role_permissions')->where('role_id', $role->id)->whereIn('permission_key', $keys)->delete();
        }
    }
}
