<?php

namespace App\Domain\Identity;

use App\Models\Organization;
use App\Models\OrganizationMembership;

final readonly class AcceptedInvitation
{
    public const JOINED = 'joined';

    /** The person already had an active membership; the redundant invitation was retired. */
    public const ALREADY_MEMBER = 'already_member';

    /** The person had a deactivated membership; it was reactivated with the invited roles. */
    public const REJOINED = 'rejoined';

    public function __construct(
        public Organization $organization,
        public OrganizationMembership $membership,
        public string $outcome,
    ) {}
}
