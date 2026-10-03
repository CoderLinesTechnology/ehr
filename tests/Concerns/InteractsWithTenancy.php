<?php

namespace Tests\Concerns;

use App\Domain\Identity\RoleTemplates;
use App\Domain\Platform\CreatedOrganization;
use App\Domain\Platform\CreateOrganization;
use App\Domain\Platform\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use Closure;

/**
 * Builds real tenants through the domain (roles, settings, subscription)
 * so tests exercise what production does.
 */
trait InteractsWithTenancy
{
    /** A working organization owned by a new verified user (org administrator). */
    protected function createOrganization(
        array $profile = [],
        string $plan = 'advanced',
        OrganizationStatus $status = OrganizationStatus::Active,
        ?User $owner = null,
    ): CreatedOrganization {
        $owner ??= User::factory()->create();

        return app(CreateOrganization::class)(
            profile: $profile + [
                'name' => fake()->unique()->company(),
                'country_code' => 'GH',
                'timezone' => 'Africa/Accra',
                'currency' => 'GHS',
            ],
            plan: Plan::query()->where('key', $plan)->firstOrFail(),
            status: $status,
            owner: $owner,
        );
    }

    /**
     * Add an active staff member with one of the organization's roles
     * (template key, e.g. 'clinician', 'receptionist', 'org_admin').
     */
    protected function addStaff(Organization $organization, string $roleKey = 'clinician', array $membership = [], ?User $user = null): OrganizationMembership
    {
        $user ??= User::factory()->create();

        return $this->inTenant($organization, function () use ($organization, $roleKey, $membership, $user) {
            $record = OrganizationMembership::factory()->create(['user_id' => $user->id] + $membership);
            $role = Role::query()->forOrganization($organization->id)->where('key', $roleKey)->firstOrFail();
            $record->roles()->attach($role->id);

            return $record->load('user');
        });
    }

    /** Run $callback inside $organization (optionally as $membership), restoring the previous context. */
    protected function inTenant(Organization $organization, Closure $callback, ?OrganizationMembership $membership = null): mixed
    {
        return app(TenantContext::class)->runAs($organization, $callback, $membership);
    }

    /** A user holding a platform role, with confirmed 2FA unless $withTwoFactor is false. */
    protected function platformUser(string $roleKey = RoleTemplates::SUPER_ADMIN, bool $withTwoFactor = true): User
    {
        $factory = User::factory();
        $user = ($withTwoFactor ? $factory->withTwoFactor() : $factory)->create();
        $role = Role::query()->platform()->where('key', $roleKey)->firstOrFail();
        $user->platformRoles()->attach($role->id, ['scope' => 'platform', 'granted_at' => now()]);

        return $user;
    }

    /**
     * Act as a platform user whose session has passed the 2FA challenge (the
     * platform console requires that proof, see EnsurePlatformAccess).
     */
    protected function actingAsPlatformUser(User $user): static
    {
        return $this->actingAs($user)->withSession([
            \App\Http\Middleware\EnsurePlatformAccess::MFA_SESSION_KEY => now()->getTimestamp(),
        ]);
    }

    /** Remove one permission from an organization role (to test a boundary closing). */
    protected function revokeFromRole(Organization $organization, string $roleKey, string $permission): void
    {
        $role = Role::query()->forOrganization($organization->id)->where('key', $roleKey)->firstOrFail();
        $role->permissionGrants()->where('permission_key', $permission)->delete();
        app(\App\Domain\Identity\PermissionResolver::class)->flush();
    }
}
