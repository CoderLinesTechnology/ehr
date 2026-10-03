<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sends a pending invitation again with a NEW token and a fresh expiry; the old
 * link stops working at once (only the new hash is stored). Works on expired
 * invitations too. The seat was already counted when the invitation was created.
 *
 * An invitation cannot be sent again within a minute of the last time it was sent: a double
 * click should not mail the person twice, and a team member (or a stolen session) must not
 * be able to use "resend" to flood an inbox. The moment of the last send is not stored on
 * its own; it is the expiry minus the validity period, which every send sets together.
 */
final class ResendInvitation
{
    public const COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly AccessGuard $guard,
        private readonly AuditLogger $audit,
        private readonly SendInvitation $send,
    ) {}

    /** @throws DomainException */
    public function __invoke(OrganizationMembership $invite): OrganizationMembership
    {
        $this->guard->requirePermission('team.manage');
        $this->guard->assertInOrganization($invite);
        $this->guard->assertCanActOn($invite);

        $organization = $this->guard->organization();
        $actor = $this->guard->actor();
        $token = InvitationTokens::generate();

        $locked = DB::transaction(function () use ($invite, $token, $actor) {
            $locked = OrganizationMembership::query()->whereKey($invite->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== MembershipStatus::Invited) {
                throw new DomainException('Only a pending invitation can be sent again.', 'not_invited');
            }

            $sentAt = $locked->invitation_expires_at?->subDays(InvitationTokens::VALID_DAYS);
            if ($sentAt !== null && $sentAt->greaterThan(now()->subSeconds(self::COOLDOWN_SECONDS))) {
                throw new DomainException('This invitation was sent a moment ago. Wait a minute before sending it again.', 'resend_too_soon');
            }

            $locked->forceFill([
                'invitation_token_hash' => InvitationTokens::hash($token),
                'invitation_expires_at' => now()->addDays(InvitationTokens::VALID_DAYS),
                'invited_by_user_id' => $actor->user_id,
            ])->save();

            $this->audit->record(
                'team.invitation_resent',
                $locked,
                metadata: ['email' => $locked->invited_email],
                summary: "Resent the invitation to {$locked->invited_email}",
            );

            return $locked;
        });

        $inviterName = User::query()->whereKey($actor->user_id)->value('name');
        ($this->send)($organization, $locked, $token, $inviterName);

        $invite->setRawAttributes($locked->getAttributes(), true);

        return $invite;
    }
}
