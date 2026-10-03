<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a custom role that nobody holds. System roles (including the locked
 * Organization Administrator) are never deleted; a role held by any membership,
 * whatever its status, must first be taken off those members.
 *
 * The role row is locked FOR UPDATE and the membership_roles foreign key is RESTRICT,
 * so assigning the role at the very moment it is deleted cannot slip through.
 */
final class DeleteRole
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly PermissionResolver $permissions,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws DomainException */
    public function __invoke(Role $role): void
    {
        $this->guard->requirePermission('roles.manage');
        $this->guard->assertInOrganization($role);
        $organization = $this->guard->organization();

        if ($role->is_locked || $role->is_system) {
            throw new DomainException("{$role->name} is a built-in role and cannot be deleted. You can edit its permissions instead.", 'role_protected');
        }

        DB::transaction(function () use ($role, $organization) {
            $locked = Role::query()->forOrganization($organization->id)->whereKey($role->id)->lockForUpdate()->firstOrFail();

            $holders = DB::table('membership_roles')
                ->where('organization_id', $organization->id)
                ->where('role_id', $locked->id)
                ->count();

            if ($holders > 0) {
                throw new DomainException(
                    $holders === 1
                        ? 'One team member still has this role. Give them another role first.'
                        : "{$holders} team members still have this role. Give them another role first.",
                    'role_in_use',
                );
            }

            $permissions = RoleGrants::current($locked);

            $this->audit->record(
                'role.deleted',
                $locked,
                before: ['name' => $locked->name, 'permissions' => $permissions],
                summary: "Deleted the role {$locked->name}",
            );

            $locked->delete(); // role_permissions cascade
        });

        $this->permissions->flush();
    }
}
