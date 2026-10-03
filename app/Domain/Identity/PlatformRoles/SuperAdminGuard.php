<?php

namespace App\Domain\Identity\PlatformRoles;

use App\Domain\Identity\RoleTemplates;
use App\Domain\Shared\DomainException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "At least one active Super Admin must always remain." A rule about the whole
 * set of holders, so no unique index can express it: instead the Super Admin
 * role row is the mutex. Every action that could remove a holder (revoking the
 * role, disabling the account) takes this lock before it checks the count, so
 * two administrators removing each other at the same moment cannot both pass.
 */
final class SuperAdminGuard
{
    /** Take the lock, and return the role (NULL when the catalogue has not been synced yet). */
    public static function lockRole(): ?Role
    {
        return Role::query()->platform()->where('key', RoleTemplates::SUPER_ADMIN)->lockForUpdate()->first();
    }

    /**
     * Refuse when $target holds the Super Admin role and no OTHER active
     * account does. Call inside the transaction, after lockRole().
     */
    public static function assertAnotherRemains(?Role $superAdminRole, User $target): void
    {
        if ($superAdminRole === null) {
            return;
        }

        $holds = DB::table('platform_user_roles')
            ->where('role_id', $superAdminRole->id)
            ->where('user_id', $target->id)
            ->exists();

        if (! $holds) {
            return;
        }

        $others = DB::table('platform_user_roles AS pur')
            ->join('users AS u', 'u.id', '=', 'pur.user_id')
            ->where('pur.role_id', $superAdminRole->id)
            ->where('pur.user_id', '!=', $target->id)
            ->where('u.status', 'active')
            ->count();

        if ($others === 0) {
            throw new DomainException('There must always be at least one active Super Admin. Give the role to someone else first.', 'last_super_admin');
        }
    }
}
