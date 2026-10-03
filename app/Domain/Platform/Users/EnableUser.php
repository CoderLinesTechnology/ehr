<?php

namespace App\Domain\Platform\Users;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Platform\PlatformAbility;
use App\Domain\Platform\PlatformAuthorizer;
use App\Domain\Shared\DomainException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Re-enables a disabled account. The person signs in again as before (their roles and memberships were never touched). */
final class EnableUser
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PlatformAuthorizer $authorizer,
    ) {}

    public function __invoke(User $target, User $actor, ?string $reason = null): User
    {
        $this->authorizer->authorize($actor, PlatformAbility::EnableUser);

        $reason = $reason !== null ? trim($reason) : null;
        $reason = $reason === '' ? null : $reason;
        if ($reason !== null && mb_strlen($reason) > 500) {
            throw new DomainException('The reason may not be longer than 500 characters.', 'reason_too_long', 'reason');
        }

        return DB::transaction(function () use ($target, $reason) {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);

            if (! $locked->isDisabled()) {
                return $target;
            }

            $locked->forceFill(['status' => 'active'])->save();

            $this->audit->record(
                'user.enabled',
                subject: $locked,
                before: ['status' => 'disabled'],
                after: ['status' => 'active'],
                metadata: array_filter(['reason' => $reason]),
                summary: "Account of {$locked->name} enabled",
                context: AuditContext::Platform,
            );

            $target->setRawAttributes($locked->getAttributes(), true);

            return $target;
        });
    }
}
