<?php

namespace App\Domain\Saas;

use App\Domain\Settings\SettingsService;
use App\Models\Organization;
use App\Models\OrganizationEntitlement;
use App\Models\PlanFeature;
use App\Models\Subscription;

/**
 * The only reader of plans/overrides. Effective value = unexpired
 * organization override, else the live subscription's plan value, else off.
 * Platform kill switches (platform.disabled_features) win over everything.
 *
 * Entitlement is not permission: a screen needs both.
 */
final class EntitlementService
{
    /** @var array<string, array{features: array<string, bool>, limits: array<string, ?int>}> */
    private array $resolved = [];

    public function __construct(private readonly SettingsService $settings) {}

    public function allows(Organization $organization, string $feature): bool
    {
        if (in_array($feature, (array) $this->settings->platform('platform.disabled_features'), true)) {
            return false;
        }

        return $this->resolve($organization)['features'][$feature] ?? false;
    }

    /** NULL means unlimited. */
    public function limit(Organization $organization, string $limitKey): ?int
    {
        $limits = $this->resolve($organization)['limits'];

        // A limit with no plan row and no override is unlimited only when the
        // organization has a plan at all; with no live subscription it is 0.
        return array_key_exists($limitKey, $limits) ? $limits[$limitKey] : ($this->hasPlan($organization) ? null : 0);
    }

    /**
     * Throws when adding $adding more would exceed the limit.
     *
     * @throws LimitReached
     */
    public function assertWithinLimit(Organization $organization, string $limitKey, int $currentUsage, int $adding = 1): void
    {
        $limit = $this->limit($organization, $limitKey);

        if ($limit !== null && $currentUsage + $adding > $limit) {
            throw LimitReached::for($limitKey, $limit);
        }
    }

    /** @return array{features: array<string, bool>, limits: array<string, ?int>} */
    public function snapshot(Organization $organization): array
    {
        return $this->resolve($organization);
    }

    public function flush(?Organization $organization = null): void
    {
        if ($organization === null) {
            $this->resolved = [];
        } else {
            unset($this->resolved[$organization->id]);
        }
    }

    private function hasPlan(Organization $organization): bool
    {
        return $this->liveSubscription($organization) !== null;
    }

    private function liveSubscription(Organization $organization): ?Subscription
    {
        return $organization->relationLoaded('liveSubscription')
            ? $organization->getRelation('liveSubscription')
            : $organization->liveSubscription()->first();
    }

    /** @return array{features: array<string, bool>, limits: array<string, ?int>} */
    private function resolve(Organization $organization): array
    {
        if (isset($this->resolved[$organization->id])) {
            return $this->resolved[$organization->id];
        }

        $features = [];
        $limits = [];

        $subscription = $this->liveSubscription($organization);
        if ($subscription !== null) {
            foreach (PlanFeature::query()->where('plan_id', $subscription->plan_id)->get() as $row) {
                if (FeatureRegistry::isLimit($row->feature_key)) {
                    $limits[$row->feature_key] = $row->limit_value;
                } else {
                    $features[$row->feature_key] = $row->enabled;
                }
            }
        }

        $overrides = OrganizationEntitlement::query()
            ->where('organization_id', $organization->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();

        foreach ($overrides as $override) {
            if (FeatureRegistry::isLimit($override->feature_key)) {
                $limits[$override->feature_key] = $override->limit_value;
            } elseif ($override->enabled !== null) {
                $features[$override->feature_key] = $override->enabled;
            }
        }

        return $this->resolved[$organization->id] = ['features' => $features, 'limits' => $limits];
    }
}
