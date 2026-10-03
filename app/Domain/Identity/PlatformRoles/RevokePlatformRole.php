<?php

namespace App\Domain\Identity\PlatformRoles;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\PermissionResolver;
use App\Domain\Identity\RoleTemplates;
use App\Domain\Platform\PlatformAbility;
use App\Domain\Platform\PlatformAuthorizer;
use App\Domain\Shared\DomainException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Takes a platform role away. Guards: nobody changes their own roles, the last
 * active Super Admin can never be removed, and an administrator can only take
 * away a role whose permissions they hold themselves.
 */
final class RevokePlatformRole
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionResolver $permissions,
        private readonly PlatformAuthorizer $authorizer,
    ) {}

    public function __invoke(User $target, string $roleKey, User $actor, string $reason): void
    {
        $this->authorizer->authorize($actor, PlatformAbility::RevokePlatformRole);

        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A reason is required to remove platform access.', 'reason_required', 'reason');
        }
        if (mb_strlen($reason) > 500) {
            throw new DomainException('The reason may not be longer than 500 characters.', 'reason_too_long', 'reason');
        }
        if ($target->id === $actor->id) {
            throw new DomainException('You cannot change your own platform roles.', 'self_change');
        }

        DB::transaction(function () use ($target, $roleKey, $actor, $reason) {
            // Lock order everywhere: the Super Admin role row, then the role being changed, then the user.
            $superAdmin = SuperAdminGuard::lockRole();

            /** @var Role|null $role */
            $role = Role::query()->platform()->where('key', $roleKey)->lockForUpdate()->first();
            if ($role === null) {
                throw new DomainException('That role does not exist.', 'unknown_role', 'role');
            }

            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);

            $before = DB::table('platform_user_roles AS pur')
                ->join('roles AS r', 'r.id', '=', 'pur.role_id')
                ->where('pur.user_id', $locked->id)
                ->orderBy('r.key')
                ->pluck('r.key')
                ->all();

            if (! in_array($role->key, $before, true)) {
                throw new DomainException('This person does not have that role.', 'not_granted', 'role');
            }

            $held = $this->permissions->platformPermissions($actor);
            foreach ($role->permissionKeys() as $permission) {
                if (! isset($held[$permission])) {
                    throw new DomainException('You can only remove a role whose permissions you hold yourself.', 'privilege_escalation', 'role');
                }
            }

            if ($role->key === RoleTemplates::SUPER_ADMIN) {
                SuperAdminGuard::assertAnotherRemains($superAdmin, $locked);
            }

            $locked->platformRoles()->detach($role->id);

            $this->audit->record(
                'platform.role_revoked',
                subject: $locked,
                before: ['roles' => $before],
                after: ['roles' => array_values(array_diff($before, [$role->key]))],
                metadata: ['role' => $role->key, 'reason' => $reason],
                summary: "{$role->name} role removed from {$locked->name}",
                context: AuditContext::Platform,
            );
        });

        $this->permissions->flush();
    }
}
