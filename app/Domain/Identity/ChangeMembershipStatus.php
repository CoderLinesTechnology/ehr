<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;

/**
 * Suspends, reactivates or deactivates a team member. The only writer of a
 * membership's status after it has been accepted (status is not mass-assignable).
 *
 *   active      → suspended | deactivated
 *   suspended   → active    | deactivated
 *   deactivated → active
 *
 * Access ends on the member's very next request (ResolveTenant requires an active
 * membership). Nothing is deleted: appointments, notes and audit keep pointing at them.
 * Guards: not yourself, not someone whose access exceeds yours (AccessGuard), never the
 * last active administrator, and bringing someone back needs a free staff seat.
 */
final class ChangeMembershipStatus
{
    /** @var array<string, list<MembershipStatus>> */
    private const TRANSITIONS = [
        'active' => [MembershipStatus::Suspended, MembershipStatus::Deactivated],
        'suspended' => [MembershipStatus::Active, MembershipStatus::Deactivated],
        'deactivated' => [MembershipStatus::Active],
    ];

    public function __construct(
        private readonly AccessGuard $guard,
        private readonly StaffSeats $seats,
        private readonly PermissionResolver $permissions,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws DomainException */
    public function __invoke(OrganizationMembership $membership, MembershipStatus $to, ?string $reason = null): OrganizationMembership
    {
        $this->guard->requirePermission('team.manage');
        $this->guard->assertInOrganization($membership);
        $actor = $this->guard->actor();

        if ($membership->id === $actor->id) {
            throw new DomainException('You cannot suspend or deactivate your own membership.', 'self_action');
        }

        $this->guard->assertCanActOn($membership);

        $organization = $this->guard->organization();
        $reason = $reason === null ? null : mb_substr(trim($reason), 0, 500);

        DB::transaction(function () use ($membership, $to, $reason, $organization) {
            $this->guard->lockOrganization();
            $locked = OrganizationMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($from === $to) {
                return; // already there (a double submit): nothing to change, nothing to audit
            }

            if ($from === MembershipStatus::Invited) {
                throw new DomainException('This person has not accepted the invitation yet. Revoke the invitation instead.', 'still_invited');
            }

            if (! in_array($to, self::TRANSITIONS[$from->value] ?? [], true)) {
                throw new DomainException("A {$from->value} member cannot be changed to {$to->value}.", 'invalid_transition');
            }

            if ($to === MembershipStatus::Active) {
                // Active and invited staff hold seats; coming back takes one.
                $this->seats->assertAvailable($organization);
            }

            if ($from === MembershipStatus::Active) {
                $this->guard->assertKeepsAnAdministrator($locked);
            }

            $locked->forceFill([
                'status' => $to,
                'deactivated_at' => match ($to) {
                    MembershipStatus::Deactivated => now(),
                    MembershipStatus::Active => null,
                    default => $locked->deactivated_at,
                },
            ]);

            $name = $locked->displayName();
            $verb = match ($to) {
                MembershipStatus::Suspended => 'suspended',
                MembershipStatus::Deactivated => 'deactivated',
                default => 'reactivated',
            };

            $this->audit->record(
                "team.member_{$verb}",
                $locked,
                before: ['status' => $from->value],
                after: ['status' => $to->value],
                metadata: array_filter(['reason' => $reason]),
                summary: ucfirst($verb)." {$name}",
            );

            $locked->save();
            $membership->setRawAttributes($locked->getAttributes(), true);
        });

        $this->permissions->flush();

        return $membership;
    }
}
