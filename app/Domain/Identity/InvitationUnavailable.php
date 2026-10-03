<?php

namespace App\Domain\Identity;

use App\Domain\Shared\DomainException;

/**
 * An invitation link that cannot be used. Unknown, expired and revoked links all
 * raise this same exception with the same words, so a link cannot be probed to
 * learn which invitations ever existed.
 */
final class InvitationUnavailable extends DomainException
{
    public static function make(): self
    {
        return new self(
            'This invitation link is no longer valid. Ask your administrator to send you a new invitation.',
            'invitation_unavailable',
        );
    }
}
