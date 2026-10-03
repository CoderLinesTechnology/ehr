<?php

namespace App\Domain\Identity\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone was invited to join an organization's staff. Dispatched after commit by InviteStaff.
 * Carries ids only: never the invitation token or the email address, because listeners may
 * be queued and their payloads stored.
 */
final class MembershipInvited implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $membershipId,
        public readonly ?string $invitedByUserId,
    ) {}
}
