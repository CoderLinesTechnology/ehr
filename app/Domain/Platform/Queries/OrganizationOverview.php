<?php

namespace App\Domain\Platform\Queries;

use App\Domain\Platform\OrganizationUsage;
use App\Domain\Saas\EntitlementReport;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Saas\SubscriptionStatus;
use App\Domain\Saas\SubscriptionTransitions;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationStatusHistory;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;

/**
 * Read model behind the organization page of the platform console. Collects
 * the organization's profile, its status history, its subscription and
 * subscription history, the entitlement matrix (plan value / override /
 * effective value and expiry), usage against limits, onboarding state and the
 * recent platform activity about it.
 *
 * Counts only for usage; no client, appointment or other tenant record is read.
 * Every history list is bounded, and the page costs a fixed number of queries.
 */
final class OrganizationOverview
{
    public const STATUS_HISTORY_LIMIT = 100;

    public const SUBSCRIPTION_HISTORY_LIMIT = 50;

    public const AUDIT_LIMIT = 10;

    public function __construct(
        private readonly OrganizationUsage $usage,
        private readonly EntitlementReport $entitlements,
        private readonly EntitlementService $entitlementService,
    ) {}

    public function __invoke(Organization $organization): OrganizationOverviewData
    {
        // Loaded once and handed to the entitlement code, so none of it asks again.
        $subscription = Subscription::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', SubscriptionStatus::LIVE)
            ->with('plan')
            ->first();
        $organization->setRelation('liveSubscription', $subscription);

        $counts = ($this->usage)($organization);

        $usage = [];
        foreach ([
            'staff' => ['Staff seats', FeatureRegistry::MAX_STAFF, $counts['staff']],
            'clients' => ['Active clients', FeatureRegistry::MAX_ACTIVE_CLIENTS, $counts['clients']],
            'locations' => ['Locations', FeatureRegistry::MAX_LOCATIONS, $counts['locations']],
        ] as $key => [$label, $limitKey, $used]) {
            $limit = $this->entitlementService->limit($organization, $limitKey);
            $usage[$key] = ['label' => $label, 'used' => $used, 'limit' => $limit, 'over_limit' => $limit !== null && $used > $limit];
        }

        return new OrganizationOverviewData(
            organization: $organization,
            subscription: $subscription,
            statusHistory: OrganizationStatusHistory::query()
                ->where('organization_id', $organization->id)
                ->with('actor:id,name')
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(self::STATUS_HISTORY_LIMIT)
                ->get(),
            subscriptionHistory: SubscriptionHistory::query()
                ->where('organization_id', $organization->id)
                ->with(['fromPlan:id,name', 'toPlan:id,name', 'actor:id,name'])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(self::SUBSCRIPTION_HISTORY_LIMIT)
                ->get(),
            entitlements: ($this->entitlements)($organization),
            usage: $usage,
            appointmentsLast30Days: $counts['appointments_30d'],
            recentAudit: AuditLog::query()
                ->platformVisible()
                ->where('organization_id', $organization->id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(self::AUDIT_LIMIT)
                ->get(),
            statusTransitions: $organization->status->allowedTransitions(),
            subscriptionTransitions: $subscription !== null ? SubscriptionTransitions::allowed($subscription->status) : [],
        );
    }
}
