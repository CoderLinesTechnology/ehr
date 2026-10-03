<?php

namespace Tests\Feature\Platform;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Platform\ChangeOrganizationStatus;
use App\Domain\Platform\OrganizationStatus;
use App\Domain\Platform\Queries\OrganizationOverview;
use App\Domain\Platform\Queries\OrganizationOverviewData;
use App\Domain\Platform\UpdateOrganizationProfile;
use App\Domain\Saas\ChangeSubscriptionPlan;
use App\Domain\Saas\ChangeSubscriptionStatus;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Saas\SetEntitlementOverride;
use App\Domain\Saas\SubscriptionStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class OrganizationOverviewTest extends PlatformTestCase
{
    private function overview(Organization $organization): OrganizationOverviewData
    {
        return app(OrganizationOverview::class)($organization->fresh());
    }

    #[Test]
    public function the_overview_gathers_everything_the_platform_knows_about_one_organization(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(['name' => 'Harbour Practice'], 'starter')->organization;
        $this->travelTo(now()->addMinutes(1));
        app(ChangeOrganizationStatus::class)($organization, OrganizationStatus::Suspended, $admin, 'Unpaid invoice');
        $this->travelTo(now()->addMinutes(1));
        app(ChangeOrganizationStatus::class)($organization, OrganizationStatus::Active, $admin);
        $this->travelTo(now()->addMinutes(1));
        app(ChangeSubscriptionPlan::class)($organization, Plan::query()->where('key', 'professional')->firstOrFail(), $admin, 'Upgrade');
        $this->travelTo(now()->addMinutes(1));
        app(SetEntitlementOverride::class)($organization, FeatureRegistry::PROGRAMS, true, null, false, 'Pilot', now()->addDays(10), $admin);

        $data = $this->overview($organization);

        $this->assertInstanceOf(OrganizationOverviewData::class, $data);
        $this->assertSame('Harbour Practice', $data->organization->name);
        $this->assertSame(OrganizationStatus::Active, $data->organization->status);

        // Subscription: the live one, with its plan.
        $this->assertSame('Professional', $data->subscription->plan->name);
        $this->assertSame(SubscriptionStatus::Active, $data->subscription->status);
        $this->assertSame(60000, $data->subscription->price_minor);

        // Status history, newest first, with the person who did it.
        $this->assertSame(
            [['active', 'suspended'], ['suspended', 'active'], ['active', null]],
            $data->statusHistory->map(fn ($row) => [$row->to_status->value, $row->from_status?->value])->all(),
        );
        $this->assertSame('Unpaid invoice', $data->statusHistory[1]->reason);
        $this->assertSame($admin->name, $data->statusHistory[0]->actor->name);

        // Subscription history, newest first, plans and people named.
        $this->assertSame(['plan_changed', 'started'], $data->subscriptionHistory->pluck('event')->all());
        $this->assertSame('Starter', $data->subscriptionHistory[0]->fromPlan->name);
        $this->assertSame('Professional', $data->subscriptionHistory[0]->toPlan->name);
        $this->assertSame($admin->name, $data->subscriptionHistory[0]->actor->name);

        // Entitlements: plan value, override and effective value for every feature.
        $this->assertCount(count(FeatureRegistry::definitions()), $data->entitlements);
        $programs = collect($data->entitlements)->firstWhere('key', FeatureRegistry::PROGRAMS);
        $this->assertFalse($programs['plan_value']);
        $this->assertTrue($programs['override']['value']);
        $this->assertTrue($programs['effective']);
        $this->assertNotNull($programs['override']['expires_at']);

        // What the organization can do next.
        $this->assertSame(['suspended', 'cancelled'], array_map(fn ($s) => $s->value, $data->statusTransitions));
        $this->assertSame(['past_due', 'cancelled'], array_map(fn ($s) => $s->value, $data->subscriptionTransitions));

        // Recent platform activity about this organization.
        $actions = $data->recentAudit->pluck('action')->all();
        $this->assertSame('entitlement.override_set', $actions[0], 'Newest first.');
        $this->assertContains('subscription.plan_changed', $actions);
        $this->assertContains('organization.status_changed', $actions);
        $this->assertContains('organization.created', $actions);
    }

    #[Test]
    public function onboarding_state_comes_from_the_organization(): void
    {
        $this->signInAsPlatform();
        $organization = $this->createOrganization()->organization;
        $this->assertFalse($this->overview($organization)->onboardingCompleted());

        $organization->forceFill(['onboarding_completed_at' => now()])->save();
        $this->assertTrue($this->overview($organization)->onboardingCompleted());
    }

    #[Test]
    public function usage_is_counted_against_the_limits_that_apply_right_now(): void
    {
        $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization; // 3 staff, 150 clients, 1 location

        // Staff seats: the owner, one active and one invited count; deactivated and suspended do not.
        $this->addStaff($organization, 'clinician');
        $this->addStaff($organization, 'clinician', ['status' => MembershipStatus::Invited, 'invited_email' => 'pending@example.org']);
        $this->addStaff($organization, 'clinician', ['status' => MembershipStatus::Deactivated]);
        $this->addStaff($organization, 'clinician', ['status' => MembershipStatus::Suspended]);

        // Clients: live and active only.
        $this->makeClient($organization);
        $this->makeClient($organization);
        $this->makeClient($organization, demo: true);
        $inactive = $this->makeClient($organization);
        $this->inTenant($organization, fn () => $inactive->forceFill(['status' => 'inactive'])->save());

        // Locations: active only.
        $this->inTenant($organization, function () {
            Location::factory()->create();
            Location::factory()->create();
            Location::factory()->create(['is_active' => false]);
        });

        $usage = $this->overview($organization)->usage;

        $this->assertSame(['label' => 'Staff seats', 'used' => 3, 'limit' => 3, 'over_limit' => false], $usage['staff']);
        $this->assertSame(['label' => 'Active clients', 'used' => 2, 'limit' => 150, 'over_limit' => false], $usage['clients']);
        $this->assertSame(['label' => 'Locations', 'used' => 2, 'limit' => 1, 'over_limit' => true], $usage['locations']);
    }

    #[Test]
    public function an_override_changes_the_limit_the_usage_is_measured_against(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $this->inTenant($organization, fn () => Location::factory()->count(2)->create());
        $this->assertTrue($this->overview($organization)->usage['locations']['over_limit']);

        app(SetEntitlementOverride::class)($organization, FeatureRegistry::MAX_LOCATIONS, null, null, true, 'Multi-site pilot', null, $admin);

        $locations = $this->overview($organization)->usage['locations'];
        $this->assertNull($locations['limit'], 'Unlimited.');
        $this->assertFalse($locations['over_limit']);
    }

    #[Test]
    public function with_no_live_subscription_nothing_is_allowed_and_nothing_can_change_but_a_new_start(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::Cancelled, $admin, 'Left');

        $data = $this->overview($organization);

        $this->assertNull($data->subscription);
        $this->assertSame([], $data->subscriptionTransitions);
        $this->assertSame(0, $data->usage['staff']['limit']);
        $this->assertTrue($data->usage['staff']['over_limit'], 'The owner holds a seat the plan no longer allows.');
        $this->assertSame(['status_changed', 'started'], $data->subscriptionHistory->pluck('event')->all(), 'History outlives the subscription.');
    }

    #[Test]
    public function usage_counts_only_the_organization_asked_about(): void
    {
        $this->signInAsPlatform();
        $one = $this->createOrganization()->organization;
        $two = $this->createOrganization()->organization;
        $this->makeClient($one);
        $this->makeClient($two);
        $this->makeClient($two);
        $this->addStaff($two, 'clinician');

        $this->assertSame(1, $this->overview($one)->usage['clients']['used']);
        $this->assertSame(1, $this->overview($one)->usage['staff']['used']);
        $this->assertSame(2, $this->overview($two)->usage['clients']['used']);
        $this->assertSame(2, $this->overview($two)->usage['staff']['used']);
    }

    #[Test]
    public function recent_activity_is_only_platform_activity_about_this_organization(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization()->organization;
        $other = $this->createOrganization()->organization;
        app(UpdateOrganizationProfile::class)($organization, ['name' => 'Renamed']);
        app(UpdateOrganizationProfile::class)($other, ['name' => 'Someone Else']);
        app(AuditLogger::class)->record('appointment.cancelled', summary: 'Appointment cancelled for a named client', context: AuditContext::Organization, organizationId: $organization->id);
        app(AuditLogger::class)->record('client.viewed', summary: 'Chart opened', context: AuditContext::Organization, organizationId: $organization->id);

        $entries = $this->overview($organization)->recentAudit;

        $this->assertContains('organization.updated', $entries->pluck('action')->all());
        $this->assertFalse($entries->contains(fn ($row) => $row->organization_id !== $organization->id), 'Nothing about another organization.');
        $this->assertFalse($entries->contains(fn ($row) => $row->context === 'organization'), 'No activity inside the organization.');
        $this->assertStringNotContainsString('named client', json_encode($entries->toArray()));
        $this->assertNotNull($admin);
    }

    #[Test]
    public function every_list_is_bounded(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $subscriptionId = DB::table('subscriptions')->where('organization_id', $organization->id)->value('id');
        $planId = Plan::query()->where('key', 'starter')->value('id');

        $now = now()->addHour(); // newer than the rows the organization already has
        DB::table('organization_status_histories')->insert(array_map(fn ($i) => [
            'id' => (string) Str::uuid7(), 'organization_id' => $organization->id, 'from_status' => 'active', 'to_status' => 'active',
            'reason' => "row {$i}", 'actor_user_id' => $admin->id, 'occurred_at' => $now->addSeconds($i),
        ], range(1, 120)));
        DB::table('subscription_histories')->insert(array_map(fn ($i) => [
            'id' => (string) Str::uuid7(), 'organization_id' => $organization->id, 'subscription_id' => $subscriptionId, 'event' => 'status_changed',
            'from_plan_id' => $planId, 'to_plan_id' => $planId, 'from_status' => 'active', 'to_status' => 'active', 'reason' => "row {$i}",
            'actor_user_id' => $admin->id, 'occurred_at' => $now->addSeconds($i),
        ], range(1, 70)));
        foreach (range(1, 15) as $i) {
            app(AuditLogger::class)->record("platform.noise_{$i}", context: AuditContext::Platform, organizationId: $organization->id);
        }

        $data = $this->overview($organization);

        $this->assertCount(OrganizationOverview::STATUS_HISTORY_LIMIT, $data->statusHistory);
        $this->assertSame('row 120', $data->statusHistory->first()->reason, 'The newest 100 are kept.');
        $this->assertSame('row 21', $data->statusHistory->last()->reason);
        $this->assertCount(OrganizationOverview::SUBSCRIPTION_HISTORY_LIMIT, $data->subscriptionHistory);
        $this->assertCount(OrganizationOverview::AUDIT_LIMIT, $data->recentAudit);
    }

    #[Test]
    public function the_overview_costs_a_fixed_number_of_queries_however_long_the_histories_are(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $starter = Plan::query()->where('key', 'starter')->firstOrFail();
        $professional = Plan::query()->where('key', 'professional')->firstOrFail();
        $churn = function (int $rounds) use ($organization, $admin, $starter, $professional) {
            foreach (range(1, $rounds) as $_) {
                app(ChangeSubscriptionPlan::class)($organization, $professional, $admin, 'Up');
                app(ChangeSubscriptionPlan::class)($organization, $starter, $admin, 'Down');
                app(ChangeOrganizationStatus::class)($organization, OrganizationStatus::Suspended, $admin, 'Pause');
                app(ChangeOrganizationStatus::class)($organization, OrganizationStatus::Active, $admin);
            }
            app(SetEntitlementOverride::class)($organization, FeatureRegistry::PROGRAMS, true, null, false, 'Pilot', null, $admin);
        };
        $measure = function () use ($organization) {
            $this->overview($organization); // warm the once-per-request loads (platform settings)
            app(EntitlementService::class)->flush(); // and measure every run from the same cold start

            return $this->countQueries(fn () => $this->overview($organization))[1];
        };

        $churn(1);
        $some = $measure();

        $churn(8);
        $this->makeClient($organization);
        $this->addStaff($organization, 'clinician');
        $more = $measure();

        $churn(8);
        $evenMore = $measure();

        // Each eager load runs once per kind of row, never once per row.
        $this->assertSame($some, $more);
        $this->assertSame($more, $evenMore, 'Three times the history, the same number of queries.');
        $this->assertLessThanOrEqual(16, $evenMore, 'About fifteen reads in all; each is bounded and indexed.');
    }
}
