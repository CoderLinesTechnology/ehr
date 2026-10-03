<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Role;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Changes a role's name, description and permissions.
 *
 *  - locked roles (the Organization Administrator) are read-only;
 *  - system roles can be edited (they are the organization's data once created);
 *  - the permission set given is the complete new set, and the difference to the
 *    current set must lie within what the actor may grant or revoke (AccessGuard);
 *  - only organization permissions are accepted (platform.* never reaches an organization role).
 *
 * Takes effect immediately: the permission resolver is flushed.
 */
final class UpdateRolePermissions
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly PermissionResolver $permissions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $permissionKeys
     *
     * @throws DomainException
     */
    public function __invoke(Role $role, string $name, ?string $description, array $permissionKeys): Role
    {
        $this->guard->requirePermission('roles.manage');
        $this->guard->assertInOrganization($role);

        if ($role->is_locked) {
            throw new DomainException("{$role->name} is locked: it always holds every permission and cannot be edited.", 'role_locked');
        }

        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            throw new DomainException('Give the role a name of up to 100 characters.', 'invalid_role_name', 'name');
        }
        $description = $description === null ? null : trim($description);
        $description = $description === '' ? null : mb_substr((string) $description, 0, 500);

        $wanted = RoleGrants::normalise($permissionKeys);
        $current = RoleGrants::current($role);
        $added = array_values(array_diff($wanted, $current));
        $removed = array_values(array_diff($current, $wanted));

        // Before the transaction: a refusal's audit row must survive it.
        $this->guard->assertCanChangePermissions([...$added, ...$removed]);

        DB::transaction(function () use ($role, $name, $description, $wanted, $added, $removed) {
            $locked = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();

            // Re-diff against the locked state so two editors cannot overwrite each other blindly.
            $currentNow = RoleGrants::current($locked);
            $addedNow = array_values(array_diff($wanted, $currentNow));
            $removedNow = array_values(array_diff($currentNow, $wanted));
            if ($addedNow !== $added || $removedNow !== $removed) {
                $this->guard->assertCanChangePermissions([...$addedNow, ...$removedNow]);
            }

            $locked->fill(['name' => $name, 'description' => $description]);
            $details = $locked->isDirty(['name', 'description']);
            $beforeDetails = ['name' => $locked->getRawOriginal('name'), 'description' => $locked->getRawOriginal('description')];

            try {
                $locked->save();
            } catch (UniqueConstraintViolationException) {
                throw new DomainException('A role with that name already exists.', 'role_name_taken', 'name');
            }

            RoleGrants::revoke($locked, $removedNow);
            RoleGrants::grant($locked, $addedNow);

            if ($addedNow !== [] || $removedNow !== []) {
                $this->audit->record(
                    'role.permissions_changed',
                    $locked,
                    before: ['permissions' => $currentNow],
                    after: ['permissions' => $wanted],
                    metadata: ['added' => $addedNow, 'removed' => $removedNow, 'role' => $locked->name],
                    summary: "Changed the permissions of the role {$locked->name}",
                );
            }

            if ($details) {
                $this->audit->record(
                    'role.updated',
                    $locked,
                    before: $beforeDetails,
                    after: ['name' => $name, 'description' => $description],
                    summary: "Updated the role {$locked->name}",
                );
            }

            $role->setRawAttributes($locked->getAttributes(), true);
        });

        $this->permissions->flush();

        return $role;
    }
}
