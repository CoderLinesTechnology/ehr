<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in user accepts an invitation by its emailed token.
 *
 * Accepted only when the user's email equals the invited email (case-insensitive):
 * the token alone is not enough. The membership row is locked, then becomes the user's
 * active seat (user, status, joined_at set; token hash and expiry cleared, so the link
 * is dead from now on). Unknown, expired and revoked tokens are the same neutral refusal.
 *
 * A person who already has a membership in the organization is handled without
 * creating a second one (a unique constraint forbids it anyway):
 *   active      → the invitation is redundant and retired; nothing else changes
 *   deactivated → the membership comes back, with the roles from this invitation
 *   suspended   → refused: an invitation does not lift an administrator's suspension
 *
 * No tenant context exists here (the token identifies the organization), so rows are
 * found across tenants on purpose and the audit entry names the organization explicitly.
 */
final class AcceptInvitation
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionResolver $permissions,
        private readonly FindInvitation $find,
    ) {}

    /** @throws DomainException */
    public function __invoke(string $token, User $user): AcceptedInvitation
    {
        $hash = InvitationTokens::hash($token);

        $result = DB::transaction(function () use ($hash, $user) {
            $invite = OrganizationMembership::acrossTenants()
                ->where('invitation_token_hash', $hash)
                ->where('status', MembershipStatus::Invited->value)
                ->lockForUpdate()
                ->first();

            if ($invite === null || $invite->invitation_expires_at === null || $invite->invitation_expires_at->isPast()) {
                throw InvitationUnavailable::make();
            }

            if (! $this->find->emailMatches($invite, $user)) {
                throw new DomainException(
                    'This invitation was sent to a different email address. Sign in with the address it was sent to.',
                    'invitation_wrong_email',
                );
            }

            $organization = Organization::query()->findOrFail($invite->organization_id);

            $existing = OrganizationMembership::acrossTenants()
                ->where('organization_id', $invite->organization_id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                $invite->forceFill([
                    'user_id' => $user->id,
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                    'invitation_token_hash' => null,
                    'invitation_expires_at' => null,
                ])->save();

                $this->recordAcceptance($invite, $organization, $user, AcceptedInvitation::JOINED);

                return new AcceptedInvitation($organization, $invite, AcceptedInvitation::JOINED);
            }

            if ($existing->status === MembershipStatus::Suspended) {
                throw new DomainException(
                    'Your access to this organization is suspended. Please contact an administrator.',
                    'membership_suspended',
                );
            }

            $roleIds = DB::table('membership_roles')->where('membership_id', $invite->id)->pluck('role_id')->all();
            $roleNames = DB::table('roles')->whereIn('id', $roleIds)->orderBy('name')->pluck('name')->all();

            // The invitation row only ever existed to become a membership; this person already has one,
            // so the audit entry is about that surviving membership and the invitation row is retired.
            $this->recordAcceptance($existing, $organization, $user, $existing->status === MembershipStatus::Active
                ? AcceptedInvitation::ALREADY_MEMBER
                : AcceptedInvitation::REJOINED, $roleNames);
            $invite->delete();

            if ($existing->status === MembershipStatus::Active) {
                return new AcceptedInvitation($organization, $existing, AcceptedInvitation::ALREADY_MEMBER);
            }

            // Deactivated: the invitation replaces the seat it was holding, so no seat check is needed.
            $existing->forceFill(['status' => MembershipStatus::Active, 'deactivated_at' => null])->save();
            $existing->roles()->sync($roleIds);

            return new AcceptedInvitation($organization, $existing, AcceptedInvitation::REJOINED);
        });

        $this->permissions->flush();

        return $result;
    }

    /** @param list<string>|null $roleNames */
    private function recordAcceptance(OrganizationMembership $membership, Organization $organization, User $user, string $outcome, ?array $roleNames = null): void
    {
        $this->audit->record(
            'team.invitation_accepted',
            $membership,
            metadata: array_filter(['outcome' => $outcome, 'email' => $user->email, 'roles' => $roleNames]),
            summary: "{$user->name} accepted the invitation to join the team",
            context: AuditContext::Organization,
            organizationId: $organization->id,
        );
    }
}
