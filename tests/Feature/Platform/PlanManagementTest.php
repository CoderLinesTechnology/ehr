<?php

namespace Tests\Feature\Platform;

use App\Domain\Saas\ChangeSubscriptionPlan;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Saas\SavePlan;
use App\Domain\Shared\DomainException;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;

class PlanManagementTest extends PlatformTestCase
{
    /** @return array<string, mixed> */
    private function attributes(array $overrides = []): array
    {
        return $overrides + [
            'key' => 'community',
            'name' => 'Community',
            'description' => 'For small clinics.',
            'price_minor' => 40000,
            'currency' => 'GHS',
            'billing_interval' => 'month',
            'trial_days' => 14,
            'is_public' => true,
            'is_active' => true,
            'sort' => 5,
        ];
    }

    /** @return array<string, bool> every boolean feature, with the given ones on */
    private function features(array $on = [FeatureRegistry::CALENDAR, FeatureRegistry::CLIENTS]): array
    {
        $matrix = array_fill_keys(array_keys(FeatureRegistry::booleanOptions()), false);
        foreach ($on as $key) {
            $matrix[$key] = true;
        }

        return $matrix;
    }

    /** @return array<string, ?int> every limit feature */
    private function limits(array $values = []): array
    {
        $limits = [];
        foreach (FeatureRegistry::definitions() as $definition) {
            if ($definition['type'] === Feature::TYPE_LIMIT) {
                $limits[$definition['key']] = array_key_exists($definition['key'], $values) ? $values[$definition['key']] : 10;
            }
        }

        return $limits;
    }

    private function save(?Plan $plan, array $attributes, ?array $features = null, ?array $limits = null): Plan
    {
        return app(SavePlan::class)($plan, $attributes, $features ?? $this->features(), $limits ?? $this->limits(), auth()->user());
    }

    // ── create ──────────────────────────────────────────────────────────

    #[Test]
    public function a_plan_is_created_with_its_price_and_a_row_for_every_feature(): void
    {
        $admin = $this->signInAsPlatform();

        $plan = $this->save(null, $this->attributes(), $this->features([FeatureRegistry::CALENDAR, FeatureRegistry::BILLING]), $this->limits([FeatureRegistry::MAX_STAFF => 7, FeatureRegistry::STORAGE_GB => null]));

        $fresh = Plan::query()->findOrFail($plan->id);
        $this->assertSame('community', $fresh->key);
        $this->assertSame('Community', $fresh->name);
        $this->assertSame('For small clinics.', $fresh->description);
        $this->assertSame(40000, $fresh->price_minor);
        $this->assertSame('GHS', $fresh->currency);
        $this->assertSame('month', $fresh->billing_interval);
        $this->assertSame(14, $fresh->trial_days);
        $this->assertTrue($fresh->is_public);
        $this->assertTrue($fresh->is_active);
        $this->assertSame(5, $fresh->sort);

        $rows = PlanFeature::query()->where('plan_id', $plan->id)->get()->keyBy('feature_key');
        $this->assertCount(count(FeatureRegistry::keys()), $rows, 'An explicit row for every feature and limit.');
        $this->assertTrue($rows[FeatureRegistry::CALENDAR]->enabled);
        $this->assertTrue($rows[FeatureRegistry::BILLING]->enabled);
        $this->assertFalse($rows[FeatureRegistry::TELEHEALTH]->enabled);
        $this->assertSame(7, $rows[FeatureRegistry::MAX_STAFF]->limit_value);
        $this->assertNull($rows[FeatureRegistry::STORAGE_GB]->limit_value, 'NULL is unlimited.');

        $audit = $this->audit('plan.created');
        $this->assertSame('platform', $audit->context);
        $this->assertSame('plan', $audit->subject_type);
        $this->assertSame($plan->id, $audit->subject_id);
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame('Community', $audit->after['name']);
        $this->assertSame(40000, $audit->after['price_minor']);
        $this->assertEqualsCanonicalizing([FeatureRegistry::CALENDAR, FeatureRegistry::BILLING], $audit->after['features']);
        $this->assertSame(7, $audit->after['limits'][FeatureRegistry::MAX_STAFF]);
        $this->assertSame('unlimited', $audit->after['limits'][FeatureRegistry::STORAGE_GB]);
    }

    #[Test]
    public function a_new_plan_can_be_started_for_an_organization_and_gives_exactly_its_features(): void
    {
        $this->signInAsPlatform();
        $plan = $this->save(null, $this->attributes(), $this->features([FeatureRegistry::CALENDAR]), $this->limits([FeatureRegistry::MAX_STAFF => 2]));
        $organization = $this->createOrganization(plan: 'community')->organization;

        $entitlements = app(EntitlementService::class);
        $this->assertTrue($entitlements->allows($organization, FeatureRegistry::CALENDAR));
        $this->assertFalse($entitlements->allows($organization, FeatureRegistry::CLIENTS));
        $this->assertSame(2, $entitlements->limit($organization, FeatureRegistry::MAX_STAFF));
        $this->assertSame(40000, Subscription::query()->where('organization_id', $organization->id)->firstOrFail()->price_minor);
        $this->assertSame($plan->id, Subscription::query()->where('organization_id', $organization->id)->firstOrFail()->plan_id);
    }

    #[Test]
    public function the_plan_key_must_be_well_formed_and_unique_and_the_refusal_leaves_nothing_behind(): void
    {
        $this->signInAsPlatform();
        $before = Plan::query()->count();

        foreach (['Bad Key', '1starts-with-digit', 'a', str_repeat('k', 41), 'UPPER', 'with space', ''] as $key) {
            try {
                $this->save(null, $this->attributes(['key' => $key]));
                $this->fail("The key [{$key}] must be refused.");
            } catch (DomainException $e) {
                $this->assertSame('invalid_key', $e->errorCode(), $key);
                $this->assertSame('key', $e->field());
            }
        }

        $this->save(null, $this->attributes(['key' => 'unique-one']));
        try {
            $this->save(null, $this->attributes(['key' => 'unique-one', 'name' => 'Copy']));
            $this->fail('A duplicate key must be refused with a message.');
        } catch (DomainException $e) {
            $this->assertSame('plan_key_taken', $e->errorCode());
        }

        $this->assertSame($before + 1, Plan::query()->count());
        // The connection is still usable after the unique violation (the failed insert was rolled back to its savepoint).
        $this->assertSame(1, Plan::query()->where('key', 'unique-one')->count());
    }

    #[Test]
    public function invalid_attributes_and_matrix_values_are_refused(): void
    {
        $this->signInAsPlatform();

        $cases = [
            'blank name' => [fn () => $this->save(null, $this->attributes(['name' => '  '])), 'invalid_name'],
            'name too long' => [fn () => $this->save(null, $this->attributes(['name' => str_repeat('n', 121)])), 'invalid_name'],
            'negative price' => [fn () => $this->save(null, $this->attributes(['price_minor' => -1])), 'invalid_price'],
            'bad currency' => [fn () => $this->save(null, $this->attributes(['currency' => 'GH'])), 'invalid_currency'],
            'bad interval' => [fn () => $this->save(null, $this->attributes(['billing_interval' => 'week'])), 'invalid_interval'],
            'trial too long' => [fn () => $this->save(null, $this->attributes(['trial_days' => 366])), 'invalid_trial'],
            'negative trial' => [fn () => $this->save(null, $this->attributes(['trial_days' => -1])), 'invalid_trial'],
            'sort out of range' => [fn () => $this->save(null, $this->attributes(['sort' => 40000])), 'invalid_sort'],
            'unknown feature' => [fn () => $this->save(null, $this->attributes(), ['time_machine' => true]), 'unknown_feature'],
            'a limit passed as a feature' => [fn () => $this->save(null, $this->attributes(), [FeatureRegistry::MAX_STAFF => true]), 'unknown_feature'],
            'unknown limit' => [fn () => $this->save(null, $this->attributes(), null, ['max_dragons' => 3]), 'unknown_feature'],
            'a feature passed as a limit' => [fn () => $this->save(null, $this->attributes(), null, [FeatureRegistry::CALENDAR => 3]), 'unknown_feature'],
            'negative limit' => [fn () => $this->save(null, $this->attributes(), null, [FeatureRegistry::MAX_STAFF => -5]), 'invalid_limit'],
        ];

        $before = Plan::query()->count();
        foreach ($cases as $name => [$attempt, $code]) {
            try {
                $attempt();
                $this->fail("{$name}: must be refused.");
            } catch (DomainException $e) {
                $this->assertSame($code, $e->errorCode(), $name);
            }
        }

        $this->assertSame($before, Plan::query()->count(), 'No refused attempt created a plan.');
    }

    #[Test]
    public function the_name_is_trimmed_the_currency_upper_cased_and_a_blank_description_becomes_null(): void
    {
        $this->signInAsPlatform();

        $plan = $this->save(null, $this->attributes(['name' => '  Tidy  ', 'currency' => 'usd', 'description' => '   ']));

        $fresh = Plan::query()->findOrFail($plan->id);
        $this->assertSame('Tidy', $fresh->name);
        $this->assertSame('USD', $fresh->currency);
        $this->assertNull($fresh->description);
    }

    // ── update ──────────────────────────────────────────────────────────

    #[Test]
    public function editing_a_plan_audits_only_what_changed(): void
    {
        $admin = $this->signInAsPlatform();
        $plan = $this->save(null, $this->attributes(), $this->features([FeatureRegistry::CALENDAR]), $this->limits([FeatureRegistry::MAX_STAFF => 5]));

        $this->save($plan, $this->attributes(['name' => 'Community Plus', 'price_minor' => 45000]), $this->features([FeatureRegistry::CALENDAR, FeatureRegistry::FORMS]), $this->limits([FeatureRegistry::MAX_STAFF => 8]));

        $fresh = Plan::query()->findOrFail($plan->id);
        $this->assertSame('Community Plus', $fresh->name);
        $this->assertSame(45000, $fresh->price_minor);

        $audit = $this->audit('plan.updated');
        $this->assertSame('platform', $audit->context);
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame('Community', $audit->before['name']);
        $this->assertSame('Community Plus', $audit->after['name']);
        $this->assertSame(40000, $audit->before['price_minor']);
        $this->assertSame(45000, $audit->after['price_minor']);
        $this->assertArrayNotHasKey('currency', $audit->after, 'Unchanged fields are not repeated.');
        $this->assertFalse($audit->before['features'][FeatureRegistry::FORMS]);
        $this->assertTrue($audit->after['features'][FeatureRegistry::FORMS]);
        $this->assertSame(5, $audit->before['features'][FeatureRegistry::MAX_STAFF]);
        $this->assertSame(8, $audit->after['features'][FeatureRegistry::MAX_STAFF]);
        $this->assertArrayNotHasKey(FeatureRegistry::CALENDAR, $audit->after['features']);
    }

    #[Test]
    public function saving_a_plan_without_changes_writes_no_audit_entry(): void
    {
        $this->signInAsPlatform();
        $plan = $this->save(null, $this->attributes());
        $before = $this->auditCount('plan.updated');

        $this->save($plan, $this->attributes());

        $this->assertSame($before, $this->auditCount('plan.updated'));
    }

    #[Test]
    public function editing_a_plan_never_rewrites_what_existing_subscribers_were_sold(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $starter = Plan::query()->where('key', 'starter')->firstOrFail();
        $subscription = Subscription::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(25000, $subscription->price_minor);

        $this->save($starter, [
            'name' => 'Starter', 'description' => 'Repriced.', 'price_minor' => 99900, 'currency' => 'USD', 'billing_interval' => 'year',
            'trial_days' => 30, 'is_public' => true, 'is_active' => true, 'sort' => 1,
        ]);

        $this->assertSame(99900, Plan::query()->findOrFail($starter->id)->price_minor);
        $kept = $subscription->fresh();
        $this->assertSame(25000, $kept->price_minor, 'The subscription keeps its price.');
        $this->assertSame('GHS', $kept->currency, 'The subscription keeps its currency.');
        $this->assertSame('month', $kept->billing_interval, 'The subscription keeps its billing interval.');

        // A NEW sale on the edited plan picks up the new terms: move another organization onto it.
        $other = $this->createOrganization(plan: 'professional')->organization;
        $moved = app(ChangeSubscriptionPlan::class)($other, Plan::query()->findOrFail($starter->id), $admin, 'Downgrade');
        $this->assertSame(99900, $moved->price_minor);
        $this->assertSame('USD', $moved->currency);
        $this->assertSame('year', $moved->billing_interval);
    }

    #[Test]
    public function feature_and_limit_changes_reach_organizations_on_the_plan_immediately(): void
    {
        $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $starter = Plan::query()->where('key', 'starter')->firstOrFail();
        $entitlements = app(EntitlementService::class);

        $this->assertFalse($entitlements->allows($organization, FeatureRegistry::PROGRAMS));
        $this->assertSame(3, $entitlements->limit($organization, FeatureRegistry::MAX_STAFF));

        $features = $this->features([FeatureRegistry::CALENDAR, FeatureRegistry::PROGRAMS]);
        $this->save($starter, [
            'name' => 'Starter', 'description' => null, 'price_minor' => 25000, 'currency' => 'GHS', 'billing_interval' => 'month',
            'trial_days' => 14, 'is_public' => true, 'is_active' => true, 'sort' => 1,
        ], $features, $this->limits([FeatureRegistry::MAX_STAFF => 12]));

        $this->assertTrue($entitlements->allows($organization, FeatureRegistry::PROGRAMS));
        $this->assertFalse($entitlements->allows($organization, FeatureRegistry::CLIENT_PORTAL), 'Switched off by the same save.');
        $this->assertSame(12, $entitlements->limit($organization, FeatureRegistry::MAX_STAFF));
    }

    #[Test]
    public function an_unlimited_limit_is_stored_as_null_and_read_back_as_unlimited(): void
    {
        $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $starter = Plan::query()->where('key', 'starter')->firstOrFail();

        $this->save($starter, [
            'name' => 'Starter', 'description' => null, 'price_minor' => 25000, 'currency' => 'GHS', 'billing_interval' => 'month',
            'trial_days' => 14, 'is_public' => true, 'is_active' => true, 'sort' => 1,
        ], null, $this->limits([FeatureRegistry::MAX_LOCATIONS => null]));

        $this->assertNull(PlanFeature::query()->where('plan_id', $starter->id)->where('feature_key', FeatureRegistry::MAX_LOCATIONS)->firstOrFail()->limit_value);
        $this->assertNull(app(EntitlementService::class)->limit($organization, FeatureRegistry::MAX_LOCATIONS));
    }

    #[Test]
    public function a_plan_is_deactivated_not_deleted_and_the_last_active_plan_cannot_be_deactivated(): void
    {
        $this->signInAsPlatform();
        $plans = Plan::query()->orderBy('sort')->get();
        $this->assertGreaterThanOrEqual(2, $plans->count());

        // Deactivate every plan but one: each is allowed while another active plan remains.
        $keep = $plans->last();
        foreach ($plans as $plan) {
            if ($plan->id === $keep->id) {
                continue;
            }
            $this->save($plan, [
                'name' => $plan->name, 'description' => $plan->description, 'price_minor' => $plan->price_minor, 'currency' => $plan->currency,
                'billing_interval' => $plan->billing_interval, 'trial_days' => $plan->trial_days, 'is_public' => $plan->is_public,
                'is_active' => false, 'sort' => $plan->sort,
            ]);
        }
        $this->assertSame(1, Plan::query()->where('is_active', true)->count());
        $this->assertSame($plans->count(), Plan::query()->count(), 'No plan was deleted.');

        try {
            $this->save($keep, [
                'name' => $keep->name, 'description' => $keep->description, 'price_minor' => $keep->price_minor, 'currency' => $keep->currency,
                'billing_interval' => $keep->billing_interval, 'trial_days' => $keep->trial_days, 'is_public' => $keep->is_public,
                'is_active' => false, 'sort' => $keep->sort,
            ]);
            $this->fail('The last active plan must stay active.');
        } catch (DomainException $e) {
            $this->assertSame('last_active_plan', $e->errorCode());
        }
        $this->assertTrue(Plan::query()->findOrFail($keep->id)->is_active);
    }

    #[Test]
    public function editing_a_plan_locks_its_row(): void
    {
        $this->signInAsPlatform();
        $starter = Plan::query()->where('key', 'starter')->firstOrFail();

        $this->save($starter, [
            'name' => 'Starter', 'description' => 'Locked while edited', 'price_minor' => 25000, 'currency' => 'GHS', 'billing_interval' => 'month',
            'trial_days' => 14, 'is_public' => true, 'is_active' => true, 'sort' => 1,
        ]);

        $this->assertRowIsLocked('plans', 'id', $starter->id);
    }

    #[Test]
    public function the_key_of_an_existing_plan_cannot_be_changed_by_an_edit(): void
    {
        $this->signInAsPlatform();
        $starter = Plan::query()->where('key', 'starter')->firstOrFail();

        $this->save($starter, [
            'key' => 'renamed', 'name' => 'Starter', 'description' => null, 'price_minor' => 25000, 'currency' => 'GHS', 'billing_interval' => 'month',
            'trial_days' => 14, 'is_public' => true, 'is_active' => true, 'sort' => 1,
        ]);

        $this->assertSame('starter', Plan::query()->findOrFail($starter->id)->key);
    }
}
