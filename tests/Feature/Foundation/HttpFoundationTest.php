<?php

namespace Tests\Feature\Foundation;

use App\Domain\Platform\ChangeOrganizationStatus;
use App\Domain\Platform\OrganizationStatus;
use App\Domain\Settings\SettingsService;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HttpFoundationTest extends TestCase
{
    #[Test]
    public function guests_are_sent_to_sign_in(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/home')->assertRedirect(route('login'));
    }

    #[Test]
    public function home_sends_a_member_of_one_organization_to_its_dashboard(): void
    {
        $created = $this->createOrganization();
        $owner = $created->ownerMembership->user()->first();

        $this->actingAs($owner)->get('/home')
            ->assertRedirect(route('app.dashboard', ['organization' => $created->organization->slug]));
    }

    #[Test]
    public function home_offers_a_chooser_to_members_of_several_organizations(): void
    {
        $user = User::factory()->create();
        $this->createOrganization(owner: $user);
        $this->createOrganization(owner: $user);

        $this->actingAs($user)->get('/home')->assertRedirect(route('organizations.choose'));
        $this->actingAs($user)->get(route('organizations.choose'))->assertOk();
    }

    #[Test]
    public function a_user_without_an_organization_is_sent_to_onboarding_and_can_create_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/home')->assertRedirect(route('onboarding.organization.create'));
        $this->actingAs($user)->get(route('onboarding.organization.create'))->assertOk()->assertSee('Set up your practice');

        $response = $this->actingAs($user)->post(route('onboarding.organization.store'), [
            'name' => 'Labone Counselling',
            'country_code' => 'GH',
            'timezone' => 'Africa/Accra',
            'currency' => 'GHS',
        ]);

        $organization = Organization::query()->where('name', 'Labone Counselling')->firstOrFail();
        $response->assertRedirect(route('app.dashboard', ['organization' => $organization->slug]));
        $this->assertSame(OrganizationStatus::Trial, $organization->status);
        $this->assertTrue($user->activeMemberships()->where('organization_id', $organization->id)->exists());
    }

    #[Test]
    public function approval_mode_creates_a_pending_organization_that_cannot_be_used_yet(): void
    {
        app(SettingsService::class)->setPlatform(['registration.mode' => 'approval'], null);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('onboarding.organization.store'), [
            'name' => 'Pending Practice', 'country_code' => 'GH', 'timezone' => 'Africa/Accra', 'currency' => 'GHS',
        ]);

        $organization = Organization::query()->where('name', 'Pending Practice')->firstOrFail();
        $this->assertSame(OrganizationStatus::Pending, $organization->status);

        $this->actingAs($user)->get(route('app.dashboard', ['organization' => $organization->slug]))
            ->assertForbidden()
            ->assertSee('awaiting approval');
    }

    #[Test]
    public function onboarding_is_refused_when_registration_is_closed(): void
    {
        app(SettingsService::class)->setPlatform(['registration.mode' => 'closed'], null);

        $this->actingAs(User::factory()->create())->post(route('onboarding.organization.store'), [
            'name' => 'Nope', 'country_code' => 'GH', 'timezone' => 'Africa/Accra', 'currency' => 'GHS',
        ])->assertForbidden();
    }

    #[Test]
    public function unverified_users_cannot_reach_the_application(): void
    {
        $created = $this->createOrganization(owner: User::factory()->unverified()->create());
        $owner = $created->ownerMembership->user()->first();

        $this->actingAs($owner)->get(route('app.dashboard', ['organization' => $created->organization->slug]))
            ->assertRedirect(route('verification.notice'));
    }

    #[Test]
    public function non_members_and_unknown_organizations_get_a_404(): void
    {
        $a = $this->createOrganization();
        $b = $this->createOrganization();
        $memberOfA = $a->ownerMembership->user()->first();

        $this->actingAs($memberOfA)->get(route('app.dashboard', ['organization' => $b->organization->slug]))->assertNotFound();
        $this->actingAs($memberOfA)->get('/o/does-not-exist')->assertNotFound();
        $this->actingAs($memberOfA)->get(route('app.dashboard', ['organization' => $a->organization->slug]))->assertOk();
    }

    #[Test]
    public function deactivated_members_lose_access(): void
    {
        $created = $this->createOrganization();
        $staff = $this->addStaff($created->organization, 'receptionist');
        $this->inTenant($created->organization, fn () => OrganizationMembership::query()->whereKey($staff->id)->update(['status' => 'deactivated']));

        $this->actingAs($staff->user)->get(route('app.dashboard', ['organization' => $created->organization->slug]))->assertNotFound();
    }

    #[Test]
    public function a_suspended_organization_shows_a_status_page(): void
    {
        $created = $this->createOrganization();
        app(ChangeOrganizationStatus::class)($created->organization, OrganizationStatus::Suspended, null, 'Unpaid');

        $this->actingAs($created->ownerMembership->user()->first())
            ->get(route('app.dashboard', ['organization' => $created->organization->slug]))
            ->assertForbidden()
            ->assertSee('suspended');
    }

    #[Test]
    public function the_platform_console_is_invisible_to_ordinary_users_and_requires_two_factor(): void
    {
        $ordinary = User::factory()->create();
        $withoutMfa = $this->platformUser(withTwoFactor: false);

        $this->actingAs($ordinary)->get('/platform')->assertNotFound();
        $this->actingAs($withoutMfa)->get('/platform')->assertRedirect(route('account.security'));
    }

    #[Test]
    public function a_disabled_user_is_signed_out_on_the_next_request(): void
    {
        $created = $this->createOrganization();
        $owner = $created->ownerMembership->user()->first();
        $owner->forceFill(['status' => 'disabled'])->save();

        $this->actingAs($owner)->get(route('app.dashboard', ['organization' => $created->organization->slug]))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function responses_carry_security_headers_and_signed_in_pages_are_not_cached(): void
    {
        $created = $this->createOrganization();

        $response = $this->actingAs($created->ownerMembership->user()->first())
            ->get(route('app.dashboard', ['organization' => $created->organization->slug]));

        $response->assertOk();
        $this->assertStringContainsString("script-src 'self'", $response->headers->get('Content-Security-Policy'));
        // Staff-app pages may be framed by WellNest itself only (the telehealth call keeps running while the app is
        // browsed in a same-origin frame); everything else refuses frames: tests/Feature/Security/FramingHeadersTest.php.
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString("frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function sign_in_is_audited_and_disabled_accounts_cannot_sign_in(): void
    {
        $user = User::factory()->create(['email' => 'nurse@example.test']);

        $this->post(route('login'), ['email' => 'NURSE@example.test', 'password' => 'password-1234'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'subject_id' => $user->id]);
        $this->assertNotNull($user->fresh()->last_login_at);

        $this->post(route('logout'));
        $user->forceFill(['status' => 'disabled'])->save();

        $this->post(route('login'), ['email' => 'nurse@example.test', 'password' => 'password-1234']);
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
    }
}
