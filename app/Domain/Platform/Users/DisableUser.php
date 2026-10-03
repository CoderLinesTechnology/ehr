<?php

namespace App\Domain\Platform\Users;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\PlatformRoles\SuperAdminGuard;
use App\Domain\Platform\PlatformAbility;
use App\Domain\Platform\PlatformAuthorizer;
use App\Domain\Shared\DomainException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Disables an account: it can no longer sign in, and an open session ends on
 * its next request (EnsureUserIsActive) — stored sessions are deleted here too,
 * so the account does not stay signed in anywhere. The status column is
 * written with forceFill in this action only.
 */
final class DisableUser
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PlatformAuthorizer $authorizer,
    ) {}

    public function __invoke(User $target, User $actor, string $reason): User
    {
        $this->authorizer->authorize($actor, PlatformAbility::DisableUser);

        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A reason is required to disable an account.', 'reason_required', 'reason');
        }
        if (mb_strlen($reason) > 500) {
            throw new DomainException('The reason may not be longer than 500 characters.', 'reason_too_long', 'reason');
        }
        if ($target->id === $actor->id) {
            throw new DomainException('You cannot disable your own account.', 'self_disable');
        }

        return DB::transaction(function () use ($target, $reason) {
            $superAdmin = SuperAdminGuard::lockRole();

            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);

            if ($locked->isDisabled()) {
                return $target;
            }

            SuperAdminGuard::assertAnotherRemains($superAdmin, $locked);

            $locked->forceFill(['status' => 'disabled'])->save();
            DB::table('sessions')->where('user_id', $locked->id)->delete();

            $this->audit->record(
                'user.disabled',
                subject: $locked,
                before: ['status' => 'active'],
                after: ['status' => 'disabled'],
                metadata: ['reason' => $reason],
                summary: "Account of {$locked->name} disabled",
                context: AuditContext::Platform,
            );

            $target->setRawAttributes($locked->getAttributes(), true);

            return $target;
        });
    }
}
