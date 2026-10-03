<?php

namespace App\Domain\Saas;

use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors FeatureRegistry into `features` and creates the default plans the
 * first time (never overwrites plans an administrator has edited). New
 * features are added to existing plans as disabled / limit 0 so nothing is
 * silently granted. Idempotent: safe to run on every deploy.
 */
final class SyncFeatureCatalogue
{
    public function __invoke(?string $currency = null): void
    {
        DB::transaction(function () use ($currency) {
            $now = now();
            $definitions = FeatureRegistry::definitions();

            Feature::query()->upsert(
                array_map(fn (array $d, int $i) => [
                    'key' => $d['key'], 'name' => $d['name'], 'description' => $d['description'],
                    'type' => $d['type'], 'unit' => $d['unit'], 'sort' => $i,
                    'created_at' => $now, 'updated_at' => $now,
                ], $definitions, array_keys($definitions)),
                ['key'],
                ['name', 'description', 'type', 'unit', 'sort', 'updated_at'],
            );

            Feature::query()->whereNotIn('key', FeatureRegistry::keys())->delete();

            if (! Plan::query()->exists()) {
                $this->createDefaultPlans($currency ?? 'GHS');
            }

            // Every plan has a row for every feature (explicit beats implicit).
            foreach (Plan::query()->get() as $plan) {
                $rows = [];
                foreach ($definitions as $d) {
                    $rows[] = [
                        'plan_id' => $plan->id, 'feature_key' => $d['key'], 'enabled' => false,
                        'limit_value' => $d['type'] === Feature::TYPE_LIMIT ? 0 : null,
                    ];
                }
                PlanFeature::query()->insertOrIgnore($rows);
            }
        });
    }

    private function createDefaultPlans(string $currency): void
    {
        foreach (FeatureRegistry::defaultPlans() as $key => $spec) {
            $plan = new Plan;
            $plan->forceFill([
                'key' => $key,
                'name' => $spec['name'],
                'description' => $spec['description'],
                'price_minor' => $spec['price_minor'],
                'currency' => $currency,
                'billing_interval' => 'month',
                'trial_days' => $spec['trial_days'],
                'is_public' => $spec['is_public'] ?? true,
                'is_active' => true,
                'sort' => $spec['sort'],
            ])->save();

            $rows = [];
            foreach (FeatureRegistry::definitions() as $d) {
                $isLimit = $d['type'] === Feature::TYPE_LIMIT;
                $rows[] = [
                    'plan_id' => $plan->id,
                    'feature_key' => $d['key'],
                    'enabled' => ! $isLimit && in_array($d['key'], $spec['features'], true),
                    // Limits absent from the spec are unlimited (NULL).
                    'limit_value' => $isLimit ? ($spec['limits'][$d['key']] ?? null) : null,
                ];
            }
            PlanFeature::query()->insert($rows);
        }
    }
}
