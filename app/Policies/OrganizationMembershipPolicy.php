<?php

namespace App\Policies;

use App\Domain\Identity\AccessGuard;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Identity\PermissionResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * "May this person do this to this team member?" for routes and screens.
 *
 *   viewAny / view       team.view
 *   invite / update      team.manage (update = title, credentials, provider flag, colour)
 *   changeAccess         team.manage AND the member does not outrank the actor (roles can be edited)
 *   changeStatus         the above, and not oneself, and not someone who has not accepted yet
 *   resendInvitation /
 *   revokeInvitation     the same, for a pending invitation
 *
 * A member of another organization is a 404. These answers only decide what to offer: the team
 * actions (UpdateMember, ChangeMembershipStatus, InviteStaff, ResendInvitation, RevokeInvitation)
 * enforce them again, together with the role-assignment and last-administrator rules, so a caller
 * that skips the policy still cannot skip a rule.
 */
final class OrganizationMembershipPolicy
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PermissionResolver $permissions,
        private readonly AccessGuard $guard,
    ) {}

    public function viewAny(User $user): Response
    {
        return $this->requires($user, 'team.view');
    }

    public function view(User $user, OrganizationMembership $member): Response
    {
        return $this->scoped($user, $member, 'team.view') ?? Response::allow();
    }

    public function invite(User $user): Response
    {
        return $this->requires($user, 'team.manage');
    }

    public function update(User $user, OrganizationMembership $member): Response
    {
        return $this->scoped($user, $member, 'team.manage') ?? Response::allow();
    }

    /** Roles (and nothing else) of someone whose access does not exceed the actor's. */
    public function changeAccess(User $user, OrganizationMembership $member): Response
    {
        return $this->scoped($user, $member, 'team.manage')
            ?? ($this->guard->canActOn($member)
                ? Response::allow()
                : Response::deny('That person has more access than you do, so only an administrator can change their access.'));
    }

    /** Suspend, reactivate or deactivate. */
    public function changeStatus(User $user, OrganizationMembership $member): Response
    {
        $refusal = $this->changeAccess($user, $member);
        if ($refusal->denied()) {
            return $refusal;
        }

        if ($member->id === $this->tenant->membership()?->id) {
            return Response::deny('You cannot suspend or deactivate your own membership.');
        }

        return $member->status === MembershipStatus::Invited
            ? Response::deny('This person has not accepted the invitation yet. Revoke the invitation instead.')
            : Response::allow();
    }

    public function resendInvitation(User $user, OrganizationMembership $member): Response
    {
        return $this->pendingInvitation($user, $member);
    }

    public function revokeInvitation(User $user, OrganizationMembership $member): Response
    {
        return $this->pendingInvitation($user, $member);
    }

    private function pendingInvitation(User $user, OrganizationMembership $member): Response
    {
        $refusal = $this->changeAccess($user, $member);
        if ($refusal->denied()) {
            return $refusal;
        }

        return $member->status === MembershipStatus::Invited ? Response::allow() : Response::deny('Only a pending invitation can be changed this way.');
    }

    private function requires(User $user, string $permission): Response
    {
        $membership = $this->membership($user);

        if ($membership === null) {
            return Response::denyAsNotFound();
        }

        return $this->permissions->membershipHas($membership, $permission) ? Response::allow() : Response::deny();
    }

    /** Null when the person holds the permission and the member is in this organization; otherwise the refusal. */
    private function scoped(User $user, OrganizationMembership $member, string $permission): ?Response
    {
        $refusal = $this->requires($user, $permission);
        if ($refusal->denied()) {
            return $refusal;
        }

        return ($member->getAttributes()['organization_id'] ?? null) === $this->tenant->id() ? null : Response::denyAsNotFound();
    }

    /** The acting membership of the current request, only if it is this user's and active. */
    private function membership(User $user): ?OrganizationMembership
    {
        $membership = $this->tenant->membership();

        return ($membership !== null && $membership->user_id === $user->id && $membership->isActive()) ? $membership : null;
    }
}
