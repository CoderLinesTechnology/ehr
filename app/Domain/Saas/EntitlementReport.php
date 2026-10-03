<?php

namespace App\Domain\Saas;

use App\Domain\Settings\SettingsService;
use App\Models\Feature;
use App\Models\Organization;
use App\Models\OrganizationEntitlement;
use App\Models\PlanFeature;
use Carbon\CarbonImmutable;

/**
 * What an organization is entitled to and why: for every feature, the value on
 * its plan, any override (with reason and expiry) and the effective value.
 * The effective value always comes from EntitlementService, the only reader,
 * so this screen cannot disagree with what the application enforces.
 */
final class EntitlementReport
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return list<array{
     *     key: string, name: string, description: string, type: string, unit: ?string,
     *     plan_value: bool|int|null, plan_has_value: bool,
     *     override: ?array{value: bool|int|null, unlimited: bool, reason: string, expires_at: ?CarbonImmutable, expired: bool, granted_by: ?string},
     *     effective: bool|int|null, platform_disabled: bool
     * }>
     */
    public function __invoke(Organization $organization): array
    {
        $subscription = $organization->relationLoaded('liveSubscription')
            ? $organization->getRelation('liveSubscription')
            : $organization->liveSubscription()->first();
        // Hand it to EntitlementService, so it does not look the subscription up again.
        $organization->setRelation('liveSubscription', $subscription);

        $planRows = $subscription !== null
            ? PlanFeature::query()->where('plan_id', $subscription->plan_id)->get()->keyBy('feature_key')
            : collect();

        $overrides = OrganizationEntitlement::query()
            ->where('organization_id', $organization->id)
            ->with('grantedBy:id,name')
            ->get()
            ->keyBy('feature_key');

        $disabledEverywhere = (array) $this->settings->platform('platform.disabled_features');

        $rows = [];
        foreach (FeatureRegistry::definitions() as $definition) {
            $key = $definition['key'];
            $isLimit = $definition['type'] === Feature::TYPE_LIMIT;
            $planRow = $planRows->get($key);
            $override = $overrides->get($key);

            $rows[] = [
                'key' => $key,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'type' => $definition['type'],
                'unit' => $definition['unit'],
                'plan_value' => $planRow === null ? null : ($isLimit ? $planRow->limit_value : $planRow->enabled),
                'plan_has_value' => $planRow !== null,
                'override' => $override === null ? null : [
                    'value' => $isLimit ? $override->limit_value : $override->enabled,
                    'unlimited' => $isLimit && $override->limit_value === null,
                    'reason' => $override->reason,
                    'expires_at' => $override->expires_at,
                    'expired' => $override->isExpired(),
                    'granted_by' => $override->grantedBy?->name,
                ],
                'effective' => $isLimit ? $this->entitlements->limit($organization, $key) : $this->entitlements->allows($organization, $key),
                'platform_disabled' => ! $isLimit && in_array($key, $disabledEverywhere, true),
            ];
        }

        return $rows;
    }
}
