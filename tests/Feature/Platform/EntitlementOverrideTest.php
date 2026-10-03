<?php

namespace Tests\Feature\Platform;

use App\Domain\Saas\ChangeSubscriptionStatus;
use App\Domain\Saas\EntitlementReport;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Saas\RemoveEntitlementOverride;
use App\Domain\Saas\SetEntitlementOverride;
use App\Domain\Saas\SubscriptionStatus;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\DomainException;
use App\Models\Organization;
use App\Models\OrganizationEntitlement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;

class EntitlementOverrideTest extends PlatformTestCase
{
    private function set(Organization $organization, string $key, ?bool $enabled = null, ?int $limit = null, bool $unlimited = false, string $reason = 'Pilot agreement', ?CarbonImmutable $expiresAt = null)
    {
        return app(SetEntitlementOverride::class)($organization, $key, $enabled, $limit, $unlimited, $reason, $expiresAt, auth()->user());
    }

    /** @return array<string, mixed> */
    private function reportRow(Organization $organization, string $key): array
    {
        foreach (app(EntitlementReport::class)($organization) as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        $this->fail("No report row for [{$key}].");
    }

    private function overrides(Organization $organization): Collection
    {
        return OrganizationEntitlement::query()->where('organization_id', $organization->id)->get();
    }

    // ── SetEntitlementOverride ──────────────────────────────────────────

    #[Test]
    public function a_boolean_override_turns_a_feature_on_or_off_whatever_the_plan_says(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $entitlements = app(EntitlementService::class);

        // Warm the memoised answers: the override must be visible without any manual flush.
        $this->assertFalse($entitlements->allows($organization, FeatureRegistry::PROGRAMS));
        $this->assertTrue($entitlements->allows($organization, FeatureRegistry::CALENDAR));

        $this->set($organization, FeatureRegistry::PROGRAMS, enabled: true);
        $this->set($organization, FeatureRegistry::CALENDAR, enabled: false, reason: 'Calendar paused during migration');

        $this->assertTrue($entitlements->allows($organization, FeatureRegistry::PROGRAMS), 'On although the plan lacks it.');
        $this->assertFalse($entitlements->allows($organization, FeatureRegistry::CALENDAR), 'Off although the plan has it.');

        $row = $this->overrides($organization)->firstWhere('feature_key', FeatureRegistry::PROGRAMS);
        $this->assertTrue($row->enabled);
        $this->assertNull($row->limit_value);
        $this->assertNull($row->expires_at);
        $this->assertSame('Pilot agreement', $row->reason);
        $this->assertSame($admin->id, $row->granted_by_user_id);
    }

    #[Test]
    public function an_override_belongs_to_one_organization_only(): void
    {
        $this->signInAsPlatform();
        $one = $this->createOrganization(plan: 'starter')->organization;
        $two = $this->createOrganization(plan: 'starter')->organization;

        $this->set($one, FeatureRegistry::PROGRAMS, enabled: true);

        $entitlements = app(EntitlementService::class);
        $this->assertTrue($entitlements->allows($one, FeatureRegistry::PROGRAMS));
        $this->assertFalse($entitlements->allows($two, FeatureRegistry::PROGRAMS));
    }

    #[Test]
    public function a_limit_override_takes_a_number_or_unlimited(): void
    {
        $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $entitlements = app(EntitlementService::class);
        $this->assertSame(1, $entitlements->limit($organization, FeatureRegistry::MAX_LOCATIONS));

        $this->set($organization, FeatureRegistry::MAX_LOCATIONS, limit: 5);
        $this->assertSame(5, $entitlements->limit($organization, FeatureRegistry::MAX_LOCATIONS));

        $this->set($organization, FeatureRegistry::MAX_LOCATIONS, unlimited: true);
        $this->assertNull($entitlements->limit($organization, FeatureRegistry::MAX_LOCATIONS), 'NULL means unlimited.');

        $row = $this->overrides($organization)->firstWhere('feature_key', FeatureRegistry::MAX_LOCATIONS);
        $this->assertNull($row->limit_value);
        $this->assertNull($row->enabled, 'A limit override carries no on/off value.');

        $this->set($organization, FeatureRegistry::MAX_LOCATIONS, limit: 0, reason: 'Freeze new locations');
        $this->assertSame(0, $entitlements->limit($organization, FeatureRegistry::MAX_LOCATIONS), 'Zero is a limit, not "unlimited".');
    }

    #[Test]
    public function setting_an_override_again_replaces_it_and_keeps_one_row_per_feature(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;

        $this->set($organization, FeatureRegistry::PROGRAMS, enabled: true, reason: 'First');
        $this->set($organization, FeatureRegistry::PROGRAMS, enabled: false, reason: 'Second', expiresAt: CarbonImmutable::now()->addDays(10));

        $rows = $this->overrides($organization)->where('feature_key', FeatureRegistry::PROGRAMS);
        $this->assertCount(1, $rows);
        $this->assertFalse($rows->first()->enabled);
        $this->assertSame('Second', $rows->first()->reason);
        $this->assertNotNull($rows->first()->expires_at);

        $audit = $this->audit('entitlement.override_set');
        $this->assertSame('on', $audit->before['value'], 'The audit entry keeps what the override was before.');
        $this->assertSame('off', $audit->after['value']);
    }

    #[Test]
    public function setting_an_override_writes_an_audit_entry(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;

        $row = $this->set($organization, FeatureRegistry::MAX_STAFF, limit: 20, reason: 'Seasonal staffing', expiresAt: CarbonImmutable::now()->addDays(30));

        $audit = $this->audit('entitlement.override_set');
        $this->assertSame('platform', $audit->context);
        $this->assertSame('entitlement', $audit->subject_type);
        $this->assertSame($row->id, $audit->subject_id);
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertNull($audit->before, 'There was no override before.');
        $this->assertSame(20, $audit->after['value']);
        $this->assertNotNull($audit->after['expires_at']);
        $this->assertSame(FeatureRegistry::MAX_STAFF, $audit->metadata['feature']);
        $this->assertSame('Seasonal staffing', $audit->metadata['reason']);
        $this->assertStringContainsString('Staff seats', $audit->summary);
    }

    #[Test]
    public function an_override_with_an_expiry_stops_applying_by_itself(): void
    {
        $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $entitlements = app(EntitlementService::class);

        $this->set($organization, FeatureRegistry::PROGRAMS, enabled: true, expiresAt: CarbonImmutable::now()->addDays(7));
        $this->assertTrue($entitlements->allows($organization, FeatureRegistry::PROGRAMS));
        $this->assertFalse($this->reportRow($organization, FeatureRegistry::PROGRAMS)['override']['expired']);

        $this->travelTo(CarbonImmutable::now()->addDays(8));
        $entitlements->flush();

        $this->assertFalse($entitlements->allows($organization, FeatureRegistry::PROGRAMS), 'The plan value applies again once the override has expired.');
        $row = $this->reportRow($organization, FeatureRegistry::PROGRAMS);
        $this->assertTrue($row['override']['expired'], 'The report still lists it, marked expired, until someone removes it.');
        $this->assertFalse($row['effective']);
    }

    #[Test]
    public function an_expiry_given_in_another_timezone_is_stored_as_the_same_instant(): void
    {
        $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        // Whole seconds: the column keeps what Eloquent writes, and the sub-second part of endOfDay() is not the point here.
        $endOfDay = CarbonImmutable::now('America/New_York')->addDays(3)->endOfDay()->startOfSecond();

        $this->set($organization, FeatureRegistry::PROGRAMS, enabled: true, expiresAt: $endOfDay);

        $stored = $this->overrides($organization)->first()->expires_at;
        $this->assertTrue($stored->equalTo($endOfDay), 'The stored instant must be the instant that was given, not its wall-clock reading read as UTC.');
        $this->assertSame($endOfDay->utc()->format('Y-m-d H:i:s'), $stored->utc()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function an_override_is_refused_when_its_input_is_not_valid(): void
    {
        $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;

        $cases = [
            'unknown feature' => [fn () => $this->set($organization, 'time_machine', enabled: true), 'unknown_feature'],
            'boolean feature without on/off' => [fn () => $this->set($organization, FeatureRegistry::PROGRAMS), 'value_required'],
            'limit without number or unlimited' => [fn () => $this->set($organization, FeatureRegistry::MAX_STAFF), 'value_required'],
            'negative limit' => [fn () => $this->set($organization, FeatureRegistry::MAX_STAFF, limit: -1), 'invalid_limit'],
            'blank reason' => [fn () => $this->set($organization, FeatureRegistry::PROGRAMS, enabled: true, reason: '  '), 'reason_required'],
            'reason too long' => [fn () => $this->set($organization, FeatureRegistry::PROGRAMS, enabled: true, reason: str_repeat('x', 501)), 'reason_too_long'],
            'expiry in the past' => [fn () => $this->set($organization, FeatureRegistry::PROGRAMS, enabled: true, expiresAt: CarbonImmutable::now()->subMinute()), 'expiry_in_past'],
        ];

        foreach ($cases as $name => [$attempt, $code]) {
            try {
                $attempt();
                $this->fail("{$name}: must be refused.");
            } catch (DomainException $e) {
                $this->assertSame($code, $e->errorCode(), $name);
            }
        }

        $this->assertCount(0, $this->overrides($organization), 'Nothing was saved by any refused attempt.');
        $this->assertSame(0, $this->auditCount('entitlement.override_set'));
    }

    // ── RemoveEntitlementOverride ───────────────────────────────────────

    #[Test]
    public function removing_an_override_brings_the_plan_value_back_and_is_audited(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $entitlements = app(EntitlementService::class);
        $this->set($organization, FeatureRegistry::MAX_STAFF, limit: 20);
        $this->assertSame(20, $entitlements->limit($organization, FeatureRegistry::MAX_STAFF));

        app(RemoveEntitlementOverride::class)($organization, FeatureRegistry::MAX_STAFF, $admin, 'Pilot ended');

        $this->assertCount(0, $this->overrides($organization));
        $this->assertSame(3, $entitlements->limit($organization, FeatureRegistry::MAX_STAFF), 'Starter allows three staff again, with no manual flush.');

        $audit = $this->audit('entitlement.override_removed');
        $this->assertSame('platform', $audit->context);
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame(20, $audit->before['value']);
        $this->assertSame('Pilot ended', $audit->metadata['reason']);
        $this->assertSame(FeatureRegistry::MAX_STAFF, $audit->metadata['feature']);
    }

    #[Test]
    public function removing_needs_a_reason_and_an_existing_override(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $remove = app(RemoveEntitlementOverride::class);

        foreach ([
            'no override' => [fn () => $remove($organization, FeatureRegistry::PROGRAMS, $admin, 'Cleanup'), 'no_override'],
            'unknown feature' => [fn () => $remove($organization, 'time_machine', $admin, 'Cleanup'), 'unknown_feature'],
            'blank reason' => [fn () => $remove($organization, FeatureRegistry::PROGRAMS, $admin, ''), 'reason_required'],
        ] as $name => [$attempt, $code]) {
            try {
                $attempt();
                $this->fail("{$name}: must be refused.");
            } catch (DomainException $e) {
                $this->assertSame($code, $e->errorCode(), $name);
            }
        }

        $this->set($organization, FeatureRegistry::PROGRAMS, enabled: true);
        try {
            $remove($organization, FeatureRegistry::PROGRAMS, $admin, '  ');
            $this->fail('A blank reason must keep the override.');
        } catch (DomainException) {
            $this->assertCount(1, $this->overrides($organization));
        }
        $this->assertSame(0, $this->auditCount('entitlement.override_removed'));
    }

    // ── EntitlementReport ───────────────────────────────────────────────

    #[Test]
    public function the_report_shows_plan_value_override_and_effective_value_for_every_feature(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $this->set($organization, FeatureRegistry::PROGRAMS, enabled: true, reason: 'Pilot', expiresAt: CarbonImmutable::now()->addDays(30));
        $this->set($organization, FeatureRegistry::MAX_LOCATIONS, unlimited: true, reason: 'Multi-site pilot');

        $report = app(EntitlementReport::class)($organization);
        $this->assertCount(count(FeatureRegistry::definitions()), $report, 'Every feature and limit has a row.');

        $calendar = $this->reportRow($organization, FeatureRegistry::CALENDAR);
        $this->assertSame('boolean', $calendar['type']);
        $this->assertTrue($calendar['plan_value']);
        $this->assertNull($calendar['override']);
        $this->assertTrue($calendar['effective']);

        $programs = $this->reportRow($organization, FeatureRegistry::PROGRAMS);
        $this->assertFalse($programs['plan_value']);
        $this->assertTrue($programs['override']['value']);
        $this->assertSame('Pilot', $programs['override']['reason']);
        $this->assertSame($admin->name, $programs['override']['granted_by']);
        $this->assertFalse($programs['override']['expired']);
        $this->assertNotNull($programs['override']['expires_at']);
        $this->assertTrue($programs['effective']);

        $locations = $this->reportRow($organization, FeatureRegistry::MAX_LOCATIONS);
        $this->assertSame('limit', $locations['type']);
        $this->assertSame(1, $locations['plan_value']);
        $this->assertTrue($locations['override']['unlimited']);
        $this->assertNull($locations['effective'], 'Unlimited.');

        $staff = $this->reportRow($organization, FeatureRegistry::MAX_STAFF);
        $this->assertSame(3, $staff['plan_value']);
        $this->assertSame(3, $staff['effective']);
    }

    #[Test]
    public function the_report_flags_a_module_switched_off_platform_wide(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;

        app(SettingsService::class)->setPlatform(['platform.disabled_features' => [FeatureRegistry::CALENDAR]], $admin);

        $row = $this->reportRow($organization, FeatureRegistry::CALENDAR);
        $this->assertTrue($row['plan_value'], 'The plan still has it.');
        $this->assertTrue($row['platform_disabled']);
        $this->assertFalse($row['effective'], 'The kill switch wins over the plan.');

        // ...and over an override.
        $this->set($organization, FeatureRegistry::CALENDAR, enabled: true);
        $this->assertFalse($this->reportRow($organization, FeatureRegistry::CALENDAR)['effective']);
    }

    #[Test]
    public function with_no_live_subscription_the_plan_column_is_empty_and_everything_is_off(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::Cancelled, $admin, 'Customer left');
        $this->set($organization, FeatureRegistry::PROGRAMS, enabled: true, reason: 'Goodwill');

        $calendar = $this->reportRow($organization, FeatureRegistry::CALENDAR);
        $this->assertFalse($calendar['plan_has_value']);
        $this->assertFalse($calendar['effective']);

        $this->assertTrue($this->reportRow($organization, FeatureRegistry::PROGRAMS)['effective'], 'An override still applies.');
        $this->assertSame(0, $this->reportRow($organization, FeatureRegistry::MAX_STAFF)['effective']);
    }

    #[Test]
    public function the_report_costs_the_same_number_of_queries_however_many_overrides_there_are(): void
    {
        $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;

        $this->set($organization, FeatureRegistry::PROGRAMS, enabled: true);
        // Warm the once-per-request loads (platform settings) so both measurements start alike.
        app(EntitlementReport::class)($organization->fresh());
        app(EntitlementService::class)->flush();
        [, $few] = $this->countQueries(fn () => app(EntitlementReport::class)($organization->fresh()));

        foreach ([FeatureRegistry::GROUPS, FeatureRegistry::INSURANCE, FeatureRegistry::AI, FeatureRegistry::TELEHEALTH, FeatureRegistry::BILLING] as $key) {
            $this->set($organization, $key, enabled: true);
        }
        $this->set($organization, FeatureRegistry::MAX_STAFF, limit: 9);
        app(EntitlementService::class)->flush();
        [, $many] = $this->countQueries(fn () => app(EntitlementReport::class)($organization->fresh()));

        $this->assertSame($few, $many, 'One round trip per kind of data, not per row.');
        $this->assertLessThanOrEqual(7, $many);
    }
}
