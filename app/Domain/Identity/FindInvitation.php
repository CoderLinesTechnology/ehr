<?php

namespace App\Domain\Identity;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Looks an invitation up by its emailed token. The database only holds the SHA-256
 * of the token, so the lookup is by hash (an indexed equality on a unique column:
 * nothing about the comparison depends on how much of a guess was right).
 *
 * Returns null for unknown, revoked and expired tokens alike. This is the one place a
 * membership is found without a tenant, because the token is what identifies the
 * organization, and it is why it is cross-tenant on purpose.
 */
final class FindInvitation
{
    private const MAX_TOKEN_LENGTH = 200;

    public function __invoke(string $token): ?OrganizationMembership
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return null;
        }

        $invite = OrganizationMembership::acrossTenants()
            ->where('invitation_token_hash', InvitationTokens::hash($token))
            ->where('status', MembershipStatus::Invited->value)
            ->first();

        if ($invite === null || $invite->invitation_expires_at === null || $invite->invitation_expires_at->isPast()) {
            return null;
        }

        return $invite;
    }

    /**
     * What the acceptance page may show: the organization, who invited the person and the roles on offer.
     *
     * @return array{organization: string, inviter: ?string, roles: list<string>, email: string, expires_at: \DateTimeInterface|null}
     */
    public function details(OrganizationMembership $invite): array
    {
        $roleNames = DB::table('membership_roles')
            ->join('roles', 'roles.id', '=', 'membership_roles.role_id')
            ->where('membership_roles.membership_id', $invite->id)
            ->where('membership_roles.organization_id', $invite->organization_id)
            ->orderBy('roles.name')
            ->pluck('roles.name')
            ->all();

        return [
            'organization' => (string) Organization::query()->whereKey($invite->organization_id)->value('name'),
            'inviter' => $invite->invited_by_user_id === null ? null : User::query()->whereKey($invite->invited_by_user_id)->value('name'),
            'roles' => $roleNames,
            'email' => (string) $invite->invited_email,
            'expires_at' => $invite->invitation_expires_at,
        ];
    }

    public function emailMatches(OrganizationMembership $invite, User $user): bool
    {
        return strcasecmp(trim((string) $invite->invited_email), trim($user->email)) === 0;
    }
}
