<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws a pending invitation. An invitation is a membership that never had a
 * user, so revoking deletes that row (and its role links); nothing else about the
 * person exists to keep. Deleting also frees the seat and the "one pending
 * invitation per email" slot, and makes the old link indistinguishable from an
 * unknown one. Only rows still in status `invited` can ever be removed this way.
 */
final class RevokeInvitation
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws DomainException */
    public function __invoke(OrganizationMembership $invite): void
    {
        $this->guard->requirePermission('team.manage');
        $this->guard->assertInOrganization($invite);
        $this->guard->assertCanActOn($invite);

        DB::transaction(function () use ($invite) {
            $locked = OrganizationMembership::query()->whereKey($invite->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== MembershipStatus::Invited) {
                throw new DomainException('Only a pending invitation can be revoked.', 'not_invited');
            }

            $roles = $this->guard->rolesOf($locked)->pluck('name')->sort()->values()->all();

            $this->audit->record(
                'team.invitation_revoked',
                $locked,
                before: ['email' => $locked->invited_email, 'roles' => $roles],
                summary: "Revoked the invitation to {$locked->invited_email}",
            );

            $locked->delete();
        });
    }
}
