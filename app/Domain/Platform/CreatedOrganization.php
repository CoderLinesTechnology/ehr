<?php

namespace App\Domain\Platform;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Subscription;

final readonly class CreatedOrganization
{
    public function __construct(
        public Organization $organization,
        public OrganizationMembership $ownerMembership,
        public Subscription $subscription,
        /** Plain invitation token (only when the owner was invited by email); never stored. */
        public ?string $invitationToken,
    ) {}
}
