<?php

namespace Tests\Feature\Foundation;

use App\Domain\Audit\AuditLogger;
use App\Domain\Platform\ChangeOrganizationStatus;
use App\Domain\Platform\OrganizationStatus;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Saas\LimitReached;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\DomainException;
use App\Models\AuditLog;
use App\Models\OrganizationEntitlement;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformFoundationTest extends TestCase
{
    #[Test]
    public function audit_rows_cannot_be_changed_or_deleted_even_with_raw_sql(): void
    {
        $log = app(AuditLogger::class)->record('test.happened', summary: 'x');

        // Each attempt runs in a savepoint so the failed statement does not
        // abort the surrounding test transaction.
        foreach ([
            fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']),
            fn () => DB::table('audit_logs')->where('id', $log->id)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('The audit row should have been protected.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('insert-only', $e->getMessage());
            }
        }

        $this->assertSame('test.happened', AuditLog::query()->whereKey($log->id)->value('action'));
    }

    #[Test]
    public function audit_redacts_secrets(): void
    {
        $log = app(AuditLogger::class)->record('test.secret', after: ['password' => 'hunter2', 'nested' => ['api_key' => 'k', 'ok' => 'v']]);

        $this->assertSame('[redacted]', $log->after['password']);
        $this->assertSame('[redacted]', $log->after['nested']['api_key']);
        $this->assertSame('v', $log->after['nested']['ok']);
    }

    #[Test]
    public function organization_status_follows_the_state_machine_and_keeps_history(): void
    {
        $organization = $this->createOrganization()->organization;
        $change = app(ChangeOrganizationStatus::class);

        $change($organization, OrganizationStatus::Suspended, null, 'Unpaid invoice');
        $this->assertSame(OrganizationStatus::Suspended, $organization->status);

        $change($organization, OrganizationStatus::Active, null);
        $this->assertSame(2 + 1, $organization->statusHistory()->count()); // created + 2 changes

        $this->expectException(DomainException::class);
        $change($organization, OrganizationStatus::Pending, null);
    }

    #[Test]
    public function restrictive_status_changes_require_a_reason(): void
    {
        $organization = $this->createOrganization()->organization;

        $this->expectException(DomainException::class);

        app(ChangeOrganizationStatus::class)($organization, OrganizationStatus::Suspended, null, '  ');
    }

    #[Test]
    public function at_most_one_live_subscription_per_organization(): void
    {
        $created = $this->createOrganization();

        $this->expectException(QueryException::class);

        $copy = $created->subscription->replicate();
        $copy->save();
    }

    #[Test]
    public function entitlements_come_from_the_plan_and_overrides_win(): void
    {
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $entitlements = app(EntitlementService::class);

        $this->assertTrue($entitlements->allows($organization, FeatureRegistry::CALENDAR));
        $this->assertFalse($entitlements->allows($organization, FeatureRegistry::PROGRAMS));
        $this->assertSame(1, $entitlements->limit($organization, FeatureRegistry::MAX_LOCATIONS));

        OrganizationEntitlement::query()->create([
            'organization_id' => $organization->id, 'feature_key' => FeatureRegistry::PROGRAMS,
            'enabled' => true, 'reason' => 'Pilot',
        ]);
        OrganizationEntitlement::query()->create([
            'organization_id' => $organization->id, 'feature_key' => FeatureRegistry::MAX_LOCATIONS,
            'limit_value' => null, 'reason' => 'Unlimited for pilot',
        ]);
        $entitlements->flush();

        $this->assertTrue($entitlements->allows($organization, FeatureRegistry::PROGRAMS));
        $this->assertNull($entitlements->limit($organization, FeatureRegistry::MAX_LOCATIONS));
    }

    #[Test]
    public function expired_overrides_and_global_kill_switches_apply(): void
    {
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $entitlements = app(EntitlementService::class);

        OrganizationEntitlement::query()->create([
            'organization_id' => $organization->id, 'feature_key' => FeatureRegistry::PROGRAMS,
            'enabled' => true, 'reason' => 'Expired pilot', 'expires_at' => now()->subDay(),
        ]);
        $this->assertFalse($entitlements->allows($organization, FeatureRegistry::PROGRAMS));

        app(SettingsService::class)->setPlatform(['platform.disabled_features' => [FeatureRegistry::CALENDAR]], null);
        $this->assertFalse($entitlements->allows($organization, FeatureRegistry::CALENDAR));
    }

    #[Test]
    public function limits_are_enforced(): void
    {
        $organization = $this->createOrganization(plan: 'starter')->organization;

        $this->expectException(LimitReached::class);

        app(EntitlementService::class)->assertWithinLimit($organization, FeatureRegistry::MAX_LOCATIONS, currentUsage: 1);
    }

    #[Test]
    public function an_organization_without_a_live_subscription_has_nothing(): void
    {
        $organization = $this->createOrganization()->organization;
        Subscription::query()->where('organization_id', $organization->id)->update(['status' => 'expired']);
        $entitlements = app(EntitlementService::class);
        $entitlements->flush();
        $organization->unsetRelation('liveSubscription');

        $this->assertFalse($entitlements->allows($organization, FeatureRegistry::CALENDAR));
        $this->assertSame(0, $entitlements->limit($organization, FeatureRegistry::MAX_STAFF));
    }

    #[Test]
    public function settings_are_validated_against_the_registry(): void
    {
        $organization = $this->createOrganization(['country_code' => 'US', 'timezone' => 'America/New_York', 'currency' => 'USD'])->organization;
        $settings = app(SettingsService::class);

        $this->assertSame('m/d/Y', $settings->organization($organization, 'general.date_format'));
        $this->assertSame(24, $settings->organization($organization, 'scheduling.min_notice_hours'));

        $settings->setOrganization($organization, ['scheduling.min_notice_hours' => '48'], null);
        $this->assertSame(48, $settings->organization($organization, 'scheduling.min_notice_hours'));
        $this->assertTrue(AuditLog::query()->where('action', 'organization.setting_changed')->exists());

        $this->expectException(ValidationException::class);
        $settings->setOrganization($organization, ['scheduling.min_notice_hours' => '-5'], null);
    }

    #[Test]
    public function unknown_settings_cannot_be_written(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(SettingsService::class)->setPlatform(['platform.does_not_exist' => 'x'], null);
    }
}
