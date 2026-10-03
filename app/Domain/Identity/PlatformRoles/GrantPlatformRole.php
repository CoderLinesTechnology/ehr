<?php

namespace App\Domain\Identity\PlatformRoles;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\PermissionResolver;
use App\Domain\Platform\PlatformAbility;
use App\Domain\Platform\PlatformAuthorizer;
use App\Domain\Shared\DomainException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives an existing account a platform role. Guards: nobody changes their own
 * roles, the account must be active with a verified email address, and an
 * administrator can only hand out a role whose permissions they hold
 * themselves (granting never escalates). The person's existing sessions end and
 * their remember token rotates, so nothing opened before the grant carries
 * console access. Platform roles take effect only behind confirmed two-factor
 * authentication (EnsurePlatformAccess).
 */
final class GrantPlatformRole
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionResolver $permissions,
        private readonly PlatformAuthorizer $authorizer,
    ) {}

    public function __invoke(User $target, string $roleKey, User $actor, string $reason): Role
    {
        // The most privileged action there is: it checks the actor itself, whoever calls it.
        $this->authorizer->authorize($actor, PlatformAbility::GrantPlatformRole);

        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A reason is required to give someone platform access.', 'reason_required', 'reason');
        }
        if (mb_strlen($reason) > 500) {
            throw new DomainException('The reason may not be longer than 500 characters.', 'reason_too_long', 'reason');
        }
        if ($target->id === $actor->id) {
            throw new DomainException('You cannot change your own platform roles.', 'self_change');
        }

        $role = DB::transaction(function () use ($target, $roleKey, $actor, $reason) {
            /** @var Role|null $role */
            $role = Role::query()->platform()->where('key', $roleKey)->lockForUpdate()->first();
            if ($role === null) {
                throw new DomainException('That role does not exist.', 'unknown_role', 'role');
            }

            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);

            if (! $locked->hasVerifiedEmail()) {
                throw new DomainException('This account\'s email address is not verified yet, so it cannot be given platform access.', 'unverified_email', 'email');
            }
            if ($locked->isDisabled()) {
                throw new DomainException('This account is disabled. Enable it before giving it platform access.', 'user_disabled', 'email');
            }

            $this->assertNotEscalating($actor, $role);

            $before = $this->roleKeys($locked);
            if (in_array($role->key, $before, true)) {
                throw new DomainException('This person already has that role.', 'already_granted', 'role');
            }

            $locked->platformRoles()->attach($role->id, [
                'scope' => Role::SCOPE_PLATFORM,
                'granted_by_user_id' => $actor->id,
                'granted_at' => now(),
            ]);

            // Whatever the person was signed in to before this moment (stored sessions, a remember-me cookie) must not
            // quietly become console access. End them all: their next sign-in goes through the full login flow, and the
            // console then asks them to set up two-factor authentication.
            $sessionsEnded = DB::table('sessions')->where('user_id', $locked->id)->delete();
            $locked->forceFill(['remember_token' => Str::random(60)])->save();

            $this->audit->record(
                'platform.role_granted',
                subject: $locked,
                before: ['roles' => $before],
                after: ['roles' => [...$before, $role->key]],
                metadata: ['role' => $role->key, 'reason' => $reason, 'sessions_ended' => $sessionsEnded],
                summary: "{$role->name} role given to {$locked->name}",
                context: AuditContext::Platform,
            );

            return $role;
        });

        $this->permissions->flush();

        return $role;
    }

    /** @return list<string> */
    private function roleKeys(User $user): array
    {
        return DB::table('platform_user_roles AS pur')
            ->join('roles AS r', 'r.id', '=', 'pur.role_id')
            ->where('pur.user_id', $user->id)
            ->orderBy('r.key')
            ->pluck('r.key')
            ->all();
    }

    private function assertNotEscalating(User $actor, Role $role): void
    {
        $held = $this->permissions->platformPermissions($actor);

        foreach ($role->permissionKeys() as $permission) {
            if (! isset($held[$permission])) {
                throw new DomainException('You can only give a role whose permissions you hold yourself.', 'privilege_escalation', 'role');
            }
        }
    }
}
