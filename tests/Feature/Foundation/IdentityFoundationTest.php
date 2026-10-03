<?php

namespace Tests\Feature\Foundation;

use App\Domain\Identity\PermissionRegistry;
use App\Domain\Identity\RoleTemplates;
use App\Domain\Tenancy\TenantContext;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IdentityFoundationTest extends TestCase
{
    #[Test]
    public function a_new_organization_gets_the_default_roles_and_an_administrator(): void
    {
        $created = $this->createOrganization();

        $keys = Role::query()->forOrganization($created->organization->id)->pluck('key')->sort()->values()->all();
        $this->assertEqualsCanonicalizing(array_keys(RoleTemplates::organization()), $keys);

        $admin = Role::query()->forOrganization($created->organization->id)->where('key', RoleTemplates::ORG_ADMIN)->first();
        $this->assertTrue($admin->is_locked);
        $this->assertEqualsCanonicalizing(PermissionRegistry::keys('organization'), $admin->permissionKeys());
    }

    #[Test]
    public function the_database_refuses_a_platform_permission_on_an_organization_role(): void
    {
        $role = Role::query()->forOrganization($this->createOrganization()->organization->id)->where('key', 'clinician')->first();

        $this->expectException(QueryException::class);

        RolePermission::query()->insert(['role_id' => $role->id, 'permission_key' => 'platform.settings.manage', 'scope' => 'organization']);
    }

    #[Test]
    public function the_database_refuses_another_organizations_role_on_a_membership(): void
    {
        $a = $this->createOrganization();
        $b = $this->createOrganization();
        $roleInB = Role::query()->forOrganization($b->organization->id)->where('key', RoleTemplates::ORG_ADMIN)->first();

        $this->expectException(QueryException::class);

        DB::table('membership_roles')->insert([
            'organization_id' => $a->organization->id,
            'membership_id' => $a->ownerMembership->id,
            'role_id' => $roleInB->id,
        ]);
    }

    #[Test]
    public function permissions_come_from_the_current_membership_only(): void
    {
        $a = $this->createOrganization();
        $b = $this->createOrganization();
        $receptionist = $this->addStaff($a->organization, 'receptionist');
        $user = $receptionist->user;

        $this->actingAs($user);

        // Inside A as a receptionist.
        $this->inTenant($a->organization, function () use ($user) {
            $this->assertTrue(Gate::forUser($user)->allows('clients.view_all'));
            $this->assertFalse(Gate::forUser($user)->allows('roles.manage'));
            $this->assertFalse(Gate::forUser($user)->allows('platform.organizations.view'));
        }, $receptionist);

        // Inside B the same user has no membership at all.
        $this->inTenant($b->organization, function () use ($user) {
            $this->assertFalse(Gate::forUser($user)->allows('clients.view_all'));
        });

        // No tenant: organization permissions are never granted.
        $this->assertFalse(Gate::forUser($user)->allows('clients.view_all'));
    }

    #[Test]
    public function platform_permissions_come_from_platform_roles_and_grant_no_tenant_access(): void
    {
        $organization = $this->createOrganization()->organization;
        $superAdmin = $this->platformUser();

        $this->assertTrue(Gate::forUser($superAdmin)->allows('platform.settings.manage'));

        app(TenantContext::class)->runAs($organization, function () use ($superAdmin) {
            $this->assertFalse(Gate::forUser($superAdmin)->allows('clients.view_all'));
        });
    }

    #[Test]
    public function revoking_a_permission_from_a_role_closes_the_boundary(): void
    {
        $created = $this->createOrganization();
        $clinician = $this->addStaff($created->organization, 'clinician');

        $this->inTenant($created->organization, fn () => $this->assertTrue(Gate::forUser($clinician->user)->allows('clients.create')), $clinician);

        $this->revokeFromRole($created->organization, 'clinician', 'clients.create');

        $this->inTenant($created->organization, fn () => $this->assertFalse(Gate::forUser($clinician->user)->allows('clients.create')), $clinician);
    }

    #[Test]
    public function a_disabled_user_holds_no_permissions(): void
    {
        $created = $this->createOrganization();
        $owner = $created->ownerMembership->user()->first();
        $owner->forceFill(['status' => 'disabled'])->save();

        $this->inTenant($created->organization, fn () => $this->assertFalse(Gate::forUser($owner)->allows('clients.view_all')), $created->ownerMembership);
    }
}
