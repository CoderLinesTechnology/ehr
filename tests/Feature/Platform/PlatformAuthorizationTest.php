<?php

namespace Tests\Feature\Platform;

use App\Domain\Identity\PermissionRegistry;
use App\Domain\Identity\RoleTemplates;
use App\Domain\Platform\PlatformAbility;
use App\Domain\Platform\PlatformAuthorizer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;

class PlatformAuthorizationTest extends PlatformTestCase
{
    /** @return list<PlatformAbility> */
    private static function superAdminAbilities(): array
    {
        return PlatformAbility::cases();
    }

    /** What the Platform Administrator role may do, written out from the role's description, not from the code. */
    private static function platformAdminAbilities(): array
    {
        return [
            PlatformAbility::ViewDashboard, PlatformAbility::ViewOrganizations, PlatformAbility::CreateOrganization,
            PlatformAbility::UpdateOrganization, PlatformAbility::ChangeOrganizationStatus,
            PlatformAbility::StartSubscription, PlatformAbility::ChangeSubscriptionPlan, PlatformAbility::ChangeSubscriptionStatus,
            PlatformAbility::SetEntitlementOverride, PlatformAbility::RemoveEntitlementOverride,
            PlatformAbility::CreatePlan, PlatformAbility::UpdatePlan,
            PlatformAbility::ViewUsers, PlatformAbility::ResendVerification, PlatformAbility::ViewAuditLog,
        ];
    }

    /** Read-only operations plus the non-destructive support action. */
    private static function platformSupportAbilities(): array
    {
        return [PlatformAbility::ViewDashboard, PlatformAbility::ViewOrganizations, PlatformAbility::ViewUsers, PlatformAbility::ResendVerification];
    }

    /** @param list<PlatformAbility> $expected */
    private function assertAbilities(User $user, array $expected, string $who): void
    {
        $authorizer = app(PlatformAuthorizer::class);

        foreach (PlatformAbility::cases() as $ability) {
            $this->assertSame(
                in_array($ability, $expected, true),
                $authorizer->can($user, $ability),
                "{$who} / {$ability->name}",
            );
        }

        $this->assertEqualsCanonicalizing(
            array_map(fn (PlatformAbility $a) => $a->name, $expected),
            array_map(fn (PlatformAbility $a) => $a->name, $authorizer->abilities($user)),
            "{$who}: abilities()",
        );
    }

    #[Test]
    public function every_ability_names_a_real_platform_permission(): void
    {
        foreach (PlatformAbility::cases() as $ability) {
            $this->assertTrue(PermissionRegistry::exists($ability->permission()), "{$ability->name} -> {$ability->permission()} is not in the catalogue.");
            $this->assertTrue(PermissionRegistry::isPlatform($ability->permission()), "{$ability->name} must need a platform.* permission.");
        }
    }

    #[Test]
    public function each_platform_role_may_do_exactly_what_it_is_for(): void
    {
        $this->assertAbilities($this->platformUser(RoleTemplates::SUPER_ADMIN)->fresh(), self::superAdminAbilities(), 'super admin');
        $this->assertAbilities($this->platformUser('platform_admin')->fresh(), self::platformAdminAbilities(), 'platform admin');
        $this->assertAbilities($this->platformUser('platform_support')->fresh(), self::platformSupportAbilities(), 'platform support');
    }

    #[Test]
    public function the_support_role_cannot_manage_anything(): void
    {
        $support = $this->platformUser('platform_support')->fresh();
        $authorizer = app(PlatformAuthorizer::class);

        foreach ([
            PlatformAbility::CreateOrganization, PlatformAbility::UpdateOrganization, PlatformAbility::ChangeOrganizationStatus,
            PlatformAbility::StartSubscription, PlatformAbility::ChangeSubscriptionPlan, PlatformAbility::ChangeSubscriptionStatus,
            PlatformAbility::SetEntitlementOverride, PlatformAbility::RemoveEntitlementOverride, PlatformAbility::CreatePlan,
            PlatformAbility::UpdatePlan, PlatformAbility::UpdatePlatformSettings, PlatformAbility::DisableUser, PlatformAbility::EnableUser,
            PlatformAbility::ViewAdministrators, PlatformAbility::GrantPlatformRole, PlatformAbility::RevokePlatformRole, PlatformAbility::ViewAuditLog,
        ] as $ability) {
            $this->assertFalse($authorizer->can($support, $ability), $ability->name);

            try {
                $authorizer->authorize($support, $ability);
                $this->fail("{$ability->name} must be refused for support.");
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        $authorizer->authorize($support, PlatformAbility::ViewOrganizations);
    }

    #[Test]
    public function a_platform_role_alone_is_not_enough_without_confirmed_two_factor(): void
    {
        $withoutTwoFactor = $this->platformUser(RoleTemplates::SUPER_ADMIN, withTwoFactor: false)->fresh();

        $this->assertAbilities($withoutTwoFactor, [], 'super admin without two-factor');
    }

    #[Test]
    public function a_disabled_account_may_do_nothing_whatever_its_roles(): void
    {
        $user = $this->platformUser(RoleTemplates::SUPER_ADMIN);
        $user->forceFill(['status' => 'disabled'])->save();

        $this->assertAbilities($user->fresh(), [], 'disabled super admin');
    }

    #[Test]
    public function an_account_with_no_platform_role_and_nobody_at_all_may_do_nothing(): void
    {
        $plain = User::factory()->withTwoFactor()->create()->fresh();

        $this->assertAbilities($plain, [], 'user without platform role');
        $this->assertFalse(app(PlatformAuthorizer::class)->can(null, PlatformAbility::ViewDashboard));
        $this->assertSame([], app(PlatformAuthorizer::class)->abilities(null));

        $this->expectException(AuthorizationException::class);
        app(PlatformAuthorizer::class)->authorize(null, PlatformAbility::ViewDashboard);
    }

    #[Test]
    public function an_organization_role_never_grants_a_platform_ability(): void
    {
        $organization = $this->createOrganization()->organization;
        $owner = $organization->created_by_user_id !== null ? User::query()->findOrFail($organization->created_by_user_id) : null;
        $this->assertNotNull($owner);

        // The owner is an Organization Administrator holding every organization permission, with two-factor on.
        $owner->forceFill([
            'two_factor_secret' => encrypt('secret'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now(),
        ])->save();

        $this->assertAbilities($owner->fresh(), [], 'organization administrator');
    }

    #[Test]
    public function the_actions_that_remove_or_change_access_ask_for_a_password_confirmation(): void
    {
        $expected = [
            PlatformAbility::ChangeOrganizationStatus, PlatformAbility::ChangeSubscriptionPlan, PlatformAbility::ChangeSubscriptionStatus,
            PlatformAbility::UpdatePlan, PlatformAbility::UpdatePlatformSettings, PlatformAbility::DisableUser, PlatformAbility::EnableUser,
            PlatformAbility::GrantPlatformRole, PlatformAbility::RevokePlatformRole,
        ];

        foreach (PlatformAbility::cases() as $ability) {
            $this->assertSame(in_array($ability, $expected, true), $ability->requiresPasswordConfirmation(), $ability->name);
        }
    }
}
