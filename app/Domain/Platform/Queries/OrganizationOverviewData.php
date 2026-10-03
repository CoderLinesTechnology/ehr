<?php

namespace App\Domain\Platform\Queries;

use App\Domain\Platform\OrganizationStatus;
use App\Domain\Saas\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationStatusHistory;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use Illuminate\Support\Collection;

/**
 * Everything the platform console shows about one organization. All of it is
 * the platform's own record of the customer: profile, lifecycle, plan,
 * entitlements and COUNTS of usage. None of it is tenant data.
 */
final readonly class OrganizationOverviewData
{
    public function __construct(
        public Organization $organization,
        /** The live subscription (its plan loaded), or NULL when there is none. */
        public ?Subscription $subscription,
        /** @var Collection<int, OrganizationStatusHistory> newest first, actor names loaded */
        public Collection $statusHistory,
        /** @var Collection<int, SubscriptionHistory> newest first, plans and actor names loaded */
        public Collection $subscriptionHistory,
        /**
         * Plan value, override and effective value of every feature and limit.
         *
         * @var list<array<string, mixed>> see EntitlementReport
         */
        public array $entitlements,
        /**
         * Usage against the limit that applies to the organization right now
         * (NULL limit = unlimited; with no live subscription every limit is 0).
         *
         * @var array<string, array{label: string, used: int, limit: ?int, over_limit: bool}>
         */
        public array $usage,
        /** Live appointments that started in the last 30 days (a count; there is no limit). */
        public int $appointmentsLast30Days,
        /** @var Collection<int, AuditLog> platform-visible entries about this organization, newest first */
        public Collection $recentAudit,
        /** @var list<OrganizationStatus> */
        public array $statusTransitions,
        /** @var list<SubscriptionStatus> */
        public array $subscriptionTransitions,
    ) {}

    public function onboardingCompleted(): bool
    {
        return $this->organization->onboarding_completed_at !== null;
    }
}
