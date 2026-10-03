<?php

namespace App\Domain\Identity;

use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Saas\LimitReached;
use App\Models\Organization;
use App\Models\OrganizationMembership;

/**
 * The `max_staff` limit counts active and invited staff. Suspended and
 * deactivated members do not hold a seat, so bringing one back (or accepting
 * an invitation on top of one) is a seat decision too.
 *
 * Counts the current tenant's memberships; call inside the organization's context.
 */
final class StaffSeats
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function inUse(): int
    {
        return OrganizationMembership::query()
            ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Invited->value])
            ->count();
    }

    /** @throws LimitReached */
    public function assertAvailable(Organization $organization, int $adding = 1): void
    {
        $this->entitlements->assertWithinLimit($organization, FeatureRegistry::MAX_STAFF, $this->inUse(), $adding);
    }
}
