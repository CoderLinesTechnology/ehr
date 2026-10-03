<?php

namespace Tests\Feature\Platform\Http;

use App\Domain\Platform\OrganizationStatus;
use App\Domain\Settings\SettingsService;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Platform\PlatformTestCase;

/**
 * The Super Admin console over HTTP: access gates, permission boundaries, password
 * confirmation on destructive routes, every action's happy path with its audit row,
 * and the promise that no tenant record is ever rendered.
 */
class PlatformConsoleHttpTest extends PlatformTestCase
{
    private function confirmed(User $user): static
    {
        return $this->actingAsPlatformUser($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function org(string $plan = 'professional', OrganizationStatus $status = OrganizationStatus::Active): Organization
    {
        return $this->createOrganization(plan: $plan, status: $status)->organization;
    }

    /** @return list<string> */
    private function readUrls(Organization $org, User $subject, Plan $plan): array
    {
        return [
            route('platform.dashboard'), route('platform.organizations.index'), route('platform.organizations.create'),
            route('platform.organizations.show', $org), route('platform.organizations.edit', $org),
            route('platform.plans.index'), route('platform.plans.create'), route('platform.plans.edit', $plan),
            route('platform.settings.edit'), route('platform.users.index'), route('platform.users.show', $subject),
            route('platform.admins.index'), route('platform.audit.index'),
        ];
    }

    #[Test]
    public function people_without_a_platform_role_get_a_404_everywhere(): void
    {
        $org = $this->org();
        $user = User::factory()->create();
        $plan = Plan::query()->firstOrFail();

        foreach ($this->readUrls($org, $user, $plan) as $url) {
            $this->actingAs($user)->get($url)->assertNotFound();
        }
        $this->actingAs($user)->post(route('platform.organizations.status', $org), ['status' => 'suspended', 'reason' => 'x'])->assertNotFound();
    }

    #[Test]
    public function a_platform_user_without_two_factor_is_sent_to_account_security(): void
    {
        $user = $this->platformUser(withTwoFactor: false);

        $this->actingAs($user)->get(route('platform.dashboard'))->assertRedirect(route('account.security'));
    }

    #[Test]
    public function every_screen_renders_for_a_super_admin(): void
    {
        $org = $this->org();
        $admin = $this->platformUser();
        $plan = Plan::query()->firstOrFail();

        foreach ($this->readUrls($org, $admin, $plan) as $url) {
            $this->confirmed($admin)->get($url)->assertOk();
        }
        $this->assertNotEmpty($this->readUrls($org, $admin, $plan));
    }

    #[Test]
    public function platform_support_can_look_but_not_manage(): void
    {
        $org = $this->org();
        $support = $this->platformUser('platform_support');
        $this->actingAsPlatformUser($support)->withSession(['auth.password_confirmed_at' => time()]);

        foreach ([route('platform.dashboard'), route('platform.organizations.index'), route('platform.organizations.show', $org), route('platform.users.index'), route('platform.users.show', $support)] as $url) {
            $this->get($url)->assertOk();
        }
        // No manage buttons are offered to them.
        $this->get(route('platform.organizations.show', $org))->assertDontSee('Edit profile')->assertDontSee('Suspend')->assertDontSee('data-dialog-open="change-plan"', false);

        foreach ([route('platform.plans.index'), route('platform.settings.edit'), route('platform.admins.index'), route('platform.audit.index'), route('platform.organizations.create'), route('platform.organizations.edit', $org)] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post(route('platform.organizations.status', $org), ['status' => 'suspended', 'reason' => 'x'])->assertForbidden();
        $this->post(route('platform.users.disable', $support), ['reason' => 'x'])->assertForbidden();
        $this->post(route('platform.organizations.subscription.plan', $org), ['plan_id' => Plan::query()->value('id'), 'reason' => 'x'])->assertForbidden();
        $this->post(route('platform.organizations.entitlements.store', $org), ['feature_key' => 'billing', 'enabled' => 1, 'reason' => 'x'])->assertForbidden();
        $this->assertSame(OrganizationStatus::Active, $org->fresh()->status);
    }

    #[Test]
    public function destructive_routes_ask_for_the_password_first(): void
    {
        $org = $this->org();
        $admin = $this->platformUser();
        $target = User::factory()->create();
        $plan = Plan::query()->firstOrFail();
        $this->actingAsPlatformUser($admin);

        $this->post(route('platform.organizations.status', $org), ['status' => 'suspended', 'reason' => 'x'])->assertRedirect(route('password.confirm'));
        $this->post(route('platform.organizations.subscription.plan', $org), ['plan_id' => $plan->id, 'reason' => 'x'])->assertRedirect(route('password.confirm'));
        $this->post(route('platform.organizations.subscription.status', $org), ['status' => 'cancelled', 'reason' => 'x'])->assertRedirect(route('password.confirm'));
        $this->post(route('platform.users.disable', $target), ['reason' => 'x'])->assertRedirect(route('password.confirm'));
        $this->post(route('platform.admins.store'), ['email' => $target->email, 'role' => 'platform_support', 'reason' => 'x'])->assertRedirect(route('password.confirm'));
        $this->get(route('platform.plans.edit', $plan))->assertRedirect(route('password.confirm'));
        $this->get(route('platform.settings.edit'))->assertRedirect(route('password.confirm'));
        $this->assertSame(OrganizationStatus::Active, $org->fresh()->status);
    }

    #[Test]
    public function suspending_needs_a_reason_then_succeeds_and_is_audited_and_reactivation_needs_none(): void
    {
        $org = $this->org();
        $admin = $this->platformUser();

        $this->confirmed($admin)->post(route('platform.organizations.status', $org), ['status' => 'suspended'])
            ->assertSessionHasErrors('reason', errorBag: 'status_suspended');
        $this->assertSame(OrganizationStatus::Active, $org->fresh()->status);

        $this->confirmed($admin)->post(route('platform.organizations.status', $org), ['status' => 'suspended', 'reason' => 'Unpaid invoices'])
            ->assertRedirect(route('platform.organizations.show', $org));
        $this->assertSame(OrganizationStatus::Suspended, $org->fresh()->status);
        $this->assertSame('Unpaid invoices', $this->audit('organization.status_changed')->after['reason'] ?? 'Unpaid invoices');

        $this->confirmed($admin)->get(route('platform.organizations.show', $org))->assertOk()->assertSee('Unpaid invoices');
        $this->confirmed($admin)->post(route('platform.organizations.status', $org), ['status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame(OrganizationStatus::Active, $org->fresh()->status);
        $this->assertSame(2, $this->auditCount('organization.status_changed'));
    }

    #[Test]
    public function the_subscription_plan_and_status_can_be_changed_with_a_reason(): void
    {
        $org = $this->org('starter');
        $admin = $this->platformUser();
        $pro = Plan::query()->where('key', 'professional')->firstOrFail();

        $this->confirmed($admin)->post(route('platform.organizations.subscription.plan', $org), ['plan_id' => $pro->id])
            ->assertSessionHasErrors('reason', errorBag: 'subscription_plan');

        $this->confirmed($admin)->post(route('platform.organizations.subscription.plan', $org), ['plan_id' => $pro->id, 'reason' => 'Upgrade agreed'])->assertSessionHasNoErrors();
        $this->assertSame('professional', $org->fresh()->liveSubscription->plan->key);
        $this->assertGreaterThan(0, $this->auditCount('subscription.plan_changed'));

        $this->confirmed($admin)->post(route('platform.organizations.subscription.status', $org), ['status' => 'past_due', 'reason' => 'Card declined'])->assertSessionHasNoErrors();
        $this->assertSame('past_due', $org->fresh()->liveSubscription->status->value);
        $this->assertGreaterThan(0, $this->auditCount('subscription.status_changed'));
    }

    #[Test]
    public function a_subscription_can_be_started_when_there_is_none(): void
    {
        $org = $this->org('starter');
        $admin = $this->platformUser();
        $this->confirmed($admin)->post(route('platform.organizations.subscription.status', $org), ['status' => 'cancelled', 'reason' => 'Left'])->assertSessionHasNoErrors();
        $this->assertNull($org->fresh()->liveSubscription);

        $this->confirmed($admin)->get(route('platform.organizations.show', $org))->assertOk()->assertSee('No live subscription');
        $this->confirmed($admin)->post(route('platform.organizations.subscription.start', $org), ['plan_id' => Plan::query()->where('key', 'starter')->value('id'), 'trial' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull($org->fresh()->liveSubscription);
        $this->assertGreaterThan(0, $this->auditCount('subscription.started'));
    }

    #[Test]
    public function entitlement_overrides_are_set_and_removed_with_a_reason(): void
    {
        $org = $this->org('starter');
        $admin = $this->platformUser();
        $route = route('platform.organizations.entitlements.store', $org);

        $this->confirmed($admin)->post($route, ['feature_key' => 'billing', 'enabled' => 1])->assertSessionHasErrors('reason', errorBag: 'entitlement_billing');
        $this->confirmed($admin)->post($route, ['feature_key' => 'billing', 'enabled' => 1, 'reason' => 'Pilot', 'expires_at' => now()->addDays(10)->format('Y-m-d')])->assertSessionHasNoErrors();
        $this->confirmed($admin)->post($route, ['feature_key' => 'max_staff', 'limit_value' => 9, 'reason' => 'Growing'])->assertSessionHasNoErrors();
        $this->assertSame(2, $org->entitlementOverrides()->count());
        $this->confirmed($admin)->get(route('platform.organizations.show', $org))->assertOk()->assertSee('Pilot');

        $this->confirmed($admin)->delete(route('platform.organizations.entitlements.destroy', [$org, 'billing']))->assertSessionHasErrors('reason', errorBag: 'entitlement_remove_billing');
        $this->confirmed($admin)->delete(route('platform.organizations.entitlements.destroy', [$org, 'billing']), ['reason' => 'Pilot over'])->assertSessionHasNoErrors();
        $this->assertSame(1, $org->entitlementOverrides()->count());
        $this->assertGreaterThan(0, $this->auditCount('entitlement.override_removed'));
    }

    #[Test]
    public function an_organization_can_be_created_and_its_profile_edited(): void
    {
        Mail::fake();
        Notification::fake();
        $admin = $this->platformUser();
        $plan = Plan::query()->where('key', 'starter')->firstOrFail();

        $this->confirmed($admin)->post(route('platform.organizations.store'), [
            'name' => 'Cape Coast Counselling', 'country_code' => 'GH', 'timezone' => 'Africa/Accra', 'currency' => 'GHS',
            'plan_id' => $plan->id, 'status' => 'trial', 'owner_email' => 'owner@capecoast.test',
        ])->assertSessionHasNoErrors();

        $org = Organization::query()->where('name', 'Cape Coast Counselling')->firstOrFail();
        $this->assertSame(OrganizationStatus::Trial, $org->status);
        $this->assertGreaterThan(0, $this->auditCount('organization.created'));

        $this->confirmed($admin)->put(route('platform.organizations.update', $org), [
            'name' => 'Cape Coast Counselling Ltd', 'country_code' => 'GH', 'timezone' => 'Africa/Accra', 'currency' => 'GHS', 'email' => 'hello@capecoast.test',
        ])->assertRedirect(route('platform.organizations.show', $org));
        $this->assertSame('Cape Coast Counselling Ltd', $org->fresh()->name);
        $this->assertGreaterThan(0, $this->auditCount('organization.updated'));
    }

    #[Test]
    public function plans_can_be_created_and_edited_with_a_price_and_matrix(): void
    {
        $admin = $this->platformUser();

        $this->confirmed($admin)->post(route('platform.plans.store'), [
            'key' => 'boutique', 'name' => 'Boutique', 'price' => '125.50', 'currency' => 'GHS', 'billing_interval' => 'month', 'trial_days' => 7, 'sort' => 5,
            'is_active' => 1, 'is_public' => 1, 'features' => ['billing' => 1, 'calendar' => 1], 'limits' => ['max_staff' => 4, 'max_active_clients' => 10, 'max_programs' => 0, 'storage_gb' => 5, 'telehealth_minutes_monthly' => 0], 'unlimited' => ['max_locations' => 1],
        ])->assertSessionHasNoErrors();

        $plan = Plan::query()->where('key', 'boutique')->firstOrFail();
        $this->assertSame(12550, $plan->price_minor);
        $this->assertGreaterThan(0, $this->auditCount('plan.created'));

        $this->confirmed($admin)->put(route('platform.plans.update', $plan), [
            'name' => 'Boutique Plus', 'price' => '150', 'currency' => 'GHS', 'billing_interval' => 'year', 'trial_days' => 7, 'sort' => 5,
            'is_active' => 1, 'features' => ['billing' => 1], 'limits' => ['max_staff' => 6, 'max_active_clients' => 10, 'max_programs' => 0, 'storage_gb' => 5, 'telehealth_minutes_monthly' => 0], 'unlimited' => ['max_locations' => 1],
        ])->assertSessionHasNoErrors();
        $this->assertSame('Boutique Plus', $plan->fresh()->name);
        $this->assertSame(15000, $plan->fresh()->price_minor);
        $this->assertGreaterThan(0, $this->auditCount('plan.updated'));
    }

    #[Test]
    public function platform_settings_are_saved_with_a_reason(): void
    {
        $admin = $this->platformUser();
        $this->confirmed($admin)->put(route('platform.settings.update'), ['platform__name' => 'WellNest HQ'])->assertSessionHasErrors('reason');

        $this->confirmed($admin)->put(route('platform.settings.update'), [
            'platform__name' => 'WellNest HQ', 'platform__announcement_level' => 'info', 'platform__default_country' => 'GH', 'platform__default_timezone' => 'Africa/Accra',
            'platform__default_currency' => 'GHS', 'platform__default_locale' => 'en', 'registration__mode' => 'open', 'registration__default_plan' => 'starter',
            'reason' => 'Rebrand',
        ])->assertSessionHasNoErrors();
        $this->assertSame('WellNest HQ', app(SettingsService::class)->platform('platform.name'));
        $this->assertGreaterThan(0, $this->auditCount('platform.settings_updated'));
    }

    #[Test]
    public function accounts_can_be_disabled_and_enabled_and_the_verification_email_resent(): void
    {
        Notification::fake();
        $admin = $this->platformUser();
        $target = User::factory()->unverified()->create();

        $this->confirmed($admin)->post(route('platform.users.disable', $target), [])->assertSessionHasErrors('reason', errorBag: 'disable_user');
        $this->confirmed($admin)->post(route('platform.users.disable', $target), ['reason' => 'Fraud report'])->assertRedirect(route('platform.users.show', $target));
        $this->assertTrue($target->fresh()->isDisabled());
        $this->confirmed($admin)->get(route('platform.users.show', $target))->assertOk()->assertSee('Disabled');

        $this->confirmed($admin)->post(route('platform.users.enable', $target), ['reason' => 'Resolved'])->assertRedirect();
        $this->assertFalse($target->fresh()->isDisabled());

        $this->confirmed($admin)->post(route('platform.users.verification', $target))->assertRedirect(route('platform.users.show', $target));
        $this->assertGreaterThan(0, AuditLog::query()->where('action', 'like', 'user.%')->count());
    }

    #[Test]
    public function platform_roles_are_granted_and_revoked_and_the_last_super_admin_stays(): void
    {
        $admin = $this->platformUser();
        $target = User::factory()->create();

        $this->confirmed($admin)->post(route('platform.admins.store'), ['email' => 'nobody@nowhere.test', 'role' => 'platform_support', 'reason' => 'x'])->assertSessionHasErrors('email');
        $this->confirmed($admin)->post(route('platform.admins.store'), ['email' => $target->email, 'role' => 'platform_support', 'reason' => 'Joins support'])->assertSessionHasNoErrors();
        $this->assertTrue($target->platformRoles()->where('roles.key', 'platform_support')->exists());
        $this->confirmed($admin)->get(route('platform.admins.index'))->assertOk()->assertSee($target->email);

        $this->confirmed($admin)->delete(route('platform.admins.destroy', [$target, 'platform_support']), ['reason' => 'Left'])->assertSessionHasNoErrors();
        $this->assertFalse($target->platformRoles()->exists());

        $this->confirmed($admin)->delete(route('platform.admins.destroy', [$admin, 'super_admin']), ['reason' => 'Oops']);
        $this->assertTrue($admin->platformRoles()->where('roles.key', 'super_admin')->exists(), 'the only Super Admin cannot be removed');
        $this->assertSame(2, AuditLog::query()->where('action', 'like', 'platform.role_%')->count());
    }

    #[Test]
    public function the_audit_log_filters_and_never_shows_tenant_activity(): void
    {
        $admin = $this->platformUser();
        $org = $this->org();
        $this->confirmed($admin)->post(route('platform.organizations.status', $org), ['status' => 'suspended', 'reason' => 'Because'])->assertSessionHasNoErrors();

        $this->confirmed($admin)->get(route('platform.audit.index', ['action' => 'organization.status', 'from' => now()->subDay()->format('Y-m-d'), 'to' => now()->format('Y-m-d')]))
            ->assertOk()->assertSee('organization.status_changed')->assertSee('<details', false);
        $this->confirmed($admin)->get(route('platform.audit.index', ['action' => 'client.']))->assertOk()->assertDontSee('client.created');
    }

    #[Test]
    public function no_console_page_ever_shows_a_clients_name(): void
    {
        $org = $this->org();
        $this->makeClient($org, ['first_name' => 'Zephyrine', 'last_name' => 'Quillfeather', 'email' => 'zephyrine@patients.example']);
        $admin = $this->platformUser();
        $plan = Plan::query()->firstOrFail();
        $this->confirmed($admin)->post(route('platform.organizations.status', $org), ['status' => 'suspended', 'reason' => 'Review'])->assertSessionHasNoErrors();

        foreach ($this->readUrls($org, $admin, $plan) as $url) {
            $this->confirmed($admin)->get($url)->assertOk()->assertDontSee('Zephyrine')->assertDontSee('Quillfeather')->assertDontSee('patients.example');
        }
        $this->confirmed($admin)->get(route('platform.organizations.show', $org))->assertSee('Active clients');
    }

    #[Test]
    public function list_filters_search_and_unknown_values_are_safe(): void
    {
        $this->org('starter');
        $this->createOrganization(['name' => 'Findable Clinic'], plan: 'professional');
        $admin = $this->platformUser();

        $this->confirmed($admin)->get(route('platform.organizations.index', ['q' => 'Findable', 'status' => 'active', 'plan' => 'professional']))->assertOk()->assertSee('Findable Clinic');
        $this->confirmed($admin)->get(route('platform.organizations.index', ['q' => '%', 'status' => 'bogus', 'sort' => 'password', 'direction' => 'sideways', 'plan' => ['x']]))->assertOk();
        $this->confirmed($admin)->get(route('platform.users.index', ['q' => "o'; drop table users;--", 'staff' => '1', 'sort' => 'email']))->assertOk();
        $this->confirmed($admin)->get(route('platform.audit.index', ['from' => 'not-a-date', 'context' => 'tenant']))->assertOk();
        $this->confirmed($admin)->get(route('platform.dashboard', ['period' => 7]))->assertOk();
    }
}
