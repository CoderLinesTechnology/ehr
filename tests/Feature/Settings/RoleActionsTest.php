<?php

namespace Tests\Feature\Settings;

use App\Domain\Identity\AccessGuard;
use App\Domain\Identity\CreateRole;
use App\Domain\Identity\DeleteRole;
use App\Domain\Identity\PermissionRegistry;
use App\Domain\Identity\RoleDirectory;
use App\Domain\Identity\RoleTemplates;
use App\Domain\Identity\UpdateRolePermissions;
use App\Domain\Platform\CreatedOrganization;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Settings\Concerns\WorksInsideOrganizations;
use Tests\TestCase;

class RoleActionsTest extends TestCase
{
    use WorksInsideOrganizations;

    private CreatedOrganization $created;

    private Organization $org;

    private OrganizationMembership $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();
        $this->org = $this->created->organization;
        $this->admin = $this->created->ownerMembership;
    }

    /** A member who can manage roles but is not an administrator: roles.manage and team.view, plus what the Staff role gives. */
    private function roleEditor(): OrganizationMembership
    {
        $editor = $this->addStaff($this->org, 'staff');
        $this->giveRole($editor, $this->makeRole($this->org, 'Role Editor', ['roles.manage', 'team.view']));

        return $editor;
    }

    #[Test]
    public function an_administrator_creates_a_custom_role(): void
    {
        $role = $this->actAs($this->admin, fn () => app(CreateRole::class)(
            'Front Desk Lead', 'Runs the front desk', ['clients.view_all', 'appointments.view_all', 'clients.view_all'],
        ));

        $this->assertMatchesRegularExpression('/^custom_[a-z0-9]{8}$/', $role->key);
        $this->assertSame($this->org->id, $role->organization_id);
        $this->assertSame(Role::SCOPE_ORGANIZATION, $role->scope);
        $this->assertFalse($role->is_system);
        $this->assertFalse($role->is_locked);
        $this->assertSame(['appointments.view_all', 'clients.view_all'], $this->permissionsOfRole($role));

        $audit = $this->lastAudit('role.created', $this->org);
        $this->assertNotNull($audit);
        $this->assertSame('Front Desk Lead', $audit->after['name']);
        $this->assertSame(['appointments.view_all', 'clients.view_all'], $audit->after['permissions']);
        $this->assertSame($this->admin->user_id, $audit->actor_user_id);
        $this->assertSame('organization', $audit->context);
    }

    #[Test]
    public function role_names_are_unique_per_organization_ignoring_case(): void
    {
        $this->actAs($this->admin, fn () => app(CreateRole::class)('Front Desk Lead', null, ['clients.view']));

        $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(CreateRole::class)('front desk  LEAD', null, [])), 'role_name_taken', 'name');
        // Built-in role names are taken too.
        $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(CreateRole::class)('Clinician', null, [])), 'role_name_taken', 'name');

        // Another organization may use the same name.
        $other = $this->createOrganization();
        $this->actAs($other->ownerMembership, fn () => app(CreateRole::class)('Front Desk Lead', null, []));
        $this->assertSame(1, Role::query()->forOrganization($other->organization->id)->where('name', 'Front Desk Lead')->count());
    }

    #[Test]
    public function a_role_needs_a_sensible_name(): void
    {
        $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(CreateRole::class)('   ', null, [])), 'invalid_role_name', 'name');
        $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(CreateRole::class)(str_repeat('x', 101), null, [])), 'invalid_role_name', 'name');
    }

    #[Test]
    public function platform_and_unknown_permissions_can_never_reach_an_organization_role(): void
    {
        foreach (PermissionRegistry::keys(PermissionRegistry::SCOPE_PLATFORM) as $key) {
            $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(CreateRole::class)('Sneaky '.$key, null, [$key])), 'platform_permission', 'permissions');
        }
        $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(CreateRole::class)('Made up', null, ['clients.teleport'])), 'unknown_permission', 'permissions');

        // Updating an existing role is refused the same way, and nothing changes.
        $clinician = $this->roleOf($this->org, 'clinician');
        $before = $this->permissionsOfRole($clinician);
        $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(UpdateRolePermissions::class)(
            $clinician, $clinician->name, null, [...$before, 'platform.settings.manage'],
        )), 'platform_permission');
        $this->assertSame($before, $this->permissionsOfRole($clinician));
    }

    #[Test]
    public function a_role_editor_can_only_grant_permissions_they_hold_and_the_attempt_is_audited(): void
    {
        $editor = $this->roleEditor();

        // Within what they hold: fine.
        $role = $this->actAs($editor, fn () => app(CreateRole::class)('Team Viewers', null, ['team.view', 'appointments.view']));
        $this->assertSame(['appointments.view', 'team.view'], $this->permissionsOfRole($role));
        $this->assertSame(0, $this->auditCount('security.privilege_escalation_blocked', $this->org));

        // Beyond what they hold: refused, nothing created, and recorded.
        $this->assertRefused(fn () => $this->actAs($editor, fn () => app(CreateRole::class)('Sneaky', null, ['team.view', 'clients.view_all', 'audit.view'])), 'privilege_escalation', 'permissions');
        $this->assertSame(0, Role::query()->forOrganization($this->org->id)->where('name', 'Sneaky')->count());

        $blocked = $this->lastAudit('security.privilege_escalation_blocked', $this->org);
        $this->assertNotNull($blocked);
        $this->assertSame($editor->user_id, $blocked->actor_user_id);
        $this->assertSame('permission_exceeds_actor', $blocked->metadata['rule']);
        $this->assertEqualsCanonicalizing(['clients.view_all', 'audit.view'], $blocked->metadata['permissions']);
    }

    #[Test]
    public function only_a_full_administrator_may_grant_what_they_do_not_hold(): void
    {
        $guard = fn (OrganizationMembership $actor) => $this->actAs($actor, fn () => [
            'full' => app(AccessGuard::class)->actorIsFullAdmin(),
            'manageable' => app(AccessGuard::class)->manageablePermissions(),
        ]);

        $asAdmin = $guard($this->admin);
        $this->assertTrue($asAdmin['full']);
        $this->assertEqualsCanonicalizing(PermissionRegistry::keys('organization'), $asAdmin['manageable']);

        $asEditor = $guard($this->roleEditor());
        $this->assertFalse($asEditor['full']);
        $this->assertContains('roles.manage', $asEditor['manageable']);
        $this->assertNotContains('clients.view_all', $asEditor['manageable']);
        $this->assertNotContains('audit.view', $asEditor['manageable']);

        // roles.manage without the Organization Administrator role is not enough…
        $this->assertFalse($asEditor['full']);
        // …and the administrator role without roles.manage would not be either (the role holds everything,
        // so the only way to lack it is a catalogue the role has not been synced with: modelled by revoking it).
        $this->revokeFromRole($this->org, RoleTemplates::ORG_ADMIN, 'roles.manage');
        $this->assertFalse($guard($this->admin)['full']);
    }

    #[Test]
    public function permission_changes_are_audited_with_before_and_after_and_apply_immediately(): void
    {
        $clinician = $this->addStaff($this->org, 'clinician');
        $role = $this->roleOf($this->org, 'clinician');
        $holds = fn (string $permission) => $this->actAs($clinician, fn () => Gate::forUser($clinician->user)->allows($permission));

        $this->assertTrue($holds('clients.create'));
        $this->assertFalse($holds('clients.archive'));

        $before = $this->permissionsOfRole($role);
        $wanted = array_values(array_unique([...array_diff($before, ['clients.create']), 'clients.archive']));

        $this->actAs($this->admin, fn () => app(UpdateRolePermissions::class)($role, $role->name, $role->description, $wanted));

        // Same process, no cache clearing by the test: the resolver was flushed by the action.
        $this->assertFalse($holds('clients.create'));
        $this->assertTrue($holds('clients.archive'));

        $audit = $this->lastAudit('role.permissions_changed', $this->org);
        $this->assertSame($before, $audit->before['permissions']);
        $this->assertEqualsCanonicalizing($wanted, $audit->after['permissions']);
        $this->assertSame(['clients.archive'], $audit->metadata['added']);
        $this->assertSame(['clients.create'], $audit->metadata['removed']);
        $this->assertSame('role', $audit->subject_type);
        $this->assertSame($role->id, $audit->subject_id);
    }

    #[Test]
    public function an_unchanged_role_leaves_no_audit_trail(): void
    {
        $role = $this->roleOf($this->org, 'clinician');
        $keys = $this->permissionsOfRole($role);

        $this->actAs($this->admin, fn () => app(UpdateRolePermissions::class)($role, $role->name, $role->description, array_reverse($keys)));

        $this->assertSame(0, $this->auditCount('role.permissions_changed', $this->org));
        $this->assertSame(0, $this->auditCount('role.updated', $this->org));
    }

    #[Test]
    public function the_administrator_role_is_locked_and_read_only(): void
    {
        $adminRole = $this->roleOf($this->org, RoleTemplates::ORG_ADMIN);
        $before = $this->permissionsOfRole($adminRole);

        $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(UpdateRolePermissions::class)($adminRole, 'Renamed', null, ['team.view'])), 'role_locked');
        $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(UpdateRolePermissions::class)($adminRole, $adminRole->name, null, $before)), 'role_locked');
        $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(DeleteRole::class)($adminRole)), 'role_protected');

        $this->assertSame($before, $this->permissionsOfRole($adminRole->fresh()));
        $this->assertSame('Organization Administrator', $adminRole->fresh()->name);
    }

    #[Test]
    public function system_roles_can_be_edited_but_not_deleted(): void
    {
        $role = $this->roleOf($this->org, 'receptionist');
        $keys = $this->permissionsOfRole($role);

        $this->actAs($this->admin, fn () => app(UpdateRolePermissions::class)($role, 'Front Desk', 'Reception and calendar', array_values(array_diff($keys, ['availability.manage_all']))));

        $role = $role->fresh();
        $this->assertSame('Front Desk', $role->name);
        $this->assertSame('receptionist', $role->key);   // the key never changes
        $this->assertTrue($role->is_system);
        $this->assertNotContains('availability.manage_all', $this->permissionsOfRole($role));
        $this->assertSame(1, $this->auditCount('role.updated', $this->org));

        $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(DeleteRole::class)($role)), 'role_protected');
        $this->assertNotNull(Role::query()->find($role->id));
    }

    #[Test]
    public function a_role_cannot_be_renamed_to_a_name_already_in_use(): void
    {
        $role = $this->roleOf($this->org, 'staff');

        $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(UpdateRolePermissions::class)(
            $role, 'clinician', null, $this->permissionsOfRole($role),
        )), 'role_name_taken', 'name');
        $this->assertSame('Staff', $role->fresh()->name);
    }

    #[Test]
    public function a_role_editor_cannot_change_permissions_they_do_not_hold(): void
    {
        $editor = $this->roleEditor();
        $role = $this->roleOf($this->org, 'receptionist');   // holds clients.view_all, which the editor does not
        $current = $this->permissionsOfRole($role);

        // Adding one the editor lacks…
        $this->assertRefused(fn () => $this->actAs($editor, fn () => app(UpdateRolePermissions::class)($role, $role->name, null, [...$current, 'audit.view'])), 'privilege_escalation', 'permissions');
        // …removing one the editor lacks…
        $this->assertRefused(fn () => $this->actAs($editor, fn () => app(UpdateRolePermissions::class)($role, $role->name, null, array_values(array_diff($current, ['clients.view_all'])))), 'privilege_escalation', 'permissions');
        $this->assertSame($current, $this->permissionsOfRole($role));

        // …but changing one they hold, while resubmitting the rest untouched, is fine.
        $this->actAs($editor, fn () => app(UpdateRolePermissions::class)($role, $role->name, null, array_values(array_diff($current, ['team.view']))));
        $this->assertSame(array_values(array_diff($current, ['team.view'])), $this->permissionsOfRole($role));
    }

    #[Test]
    public function a_custom_role_is_deleted_only_when_nobody_holds_it(): void
    {
        $role = $this->actAs($this->admin, fn () => app(CreateRole::class)('Temp Cover', null, ['team.view']));
        $holder = $this->addStaff($this->org, 'clinician');
        $this->giveRole($holder, $role);

        $e = $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(DeleteRole::class)($role)), 'role_in_use');
        $this->assertStringContainsString('One team member', $e->userMessage());
        $this->assertNotNull(Role::query()->find($role->id));

        // A deactivated member still holds it: the role stays until it is taken off them.
        $this->inTenant($this->org, fn () => $this->reload($holder)->forceFill(['status' => 'deactivated', 'deactivated_at' => now()])->save());
        $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(DeleteRole::class)($role)), 'role_in_use');

        $this->inTenant($this->org, fn () => $holder->roles()->detach($role->id));
        $this->actAs($this->admin, fn () => app(DeleteRole::class)($role));

        $this->assertNull(Role::query()->find($role->id));
        $this->assertSame([], $this->permissionsOfRole($role));   // grants went with it
        $audit = $this->lastAudit('role.deleted', $this->org);
        $this->assertSame('Temp Cover', $audit->before['name']);
        $this->assertSame(['team.view'], $audit->before['permissions']);
    }

    #[Test]
    public function the_role_overview_counts_members_and_permissions_in_few_queries(): void
    {
        $this->addStaff($this->org, 'clinician');
        $this->addStaff($this->org, 'clinician');
        $custom = $this->makeRole($this->org, 'Extra', ['team.view']);
        $this->giveRole($this->addStaff($this->org, 'staff'), $custom);

        DB::enableQueryLog();
        $roles = app(RoleDirectory::class)->overview($this->org->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $queries);
        $this->assertSame(RoleTemplates::ORG_ADMIN, $roles->first()->key);   // locked first
        $this->assertSame(2, $roles->firstWhere('key', 'clinician')->members_count);
        $this->assertSame(1, $roles->firstWhere('key', $custom->key)->members_count);
        $this->assertSame(1, $roles->firstWhere('key', $custom->key)->permissions_count);
        $this->assertSame(count(PermissionRegistry::keys('organization')), $roles->firstWhere('key', RoleTemplates::ORG_ADMIN)->permissions_count);
        $this->assertSame(1, $roles->firstWhere('key', RoleTemplates::ORG_ADMIN)->members_count);
    }

    #[Test]
    public function the_permission_to_manage_roles_is_checked_by_the_actions_themselves(): void
    {
        $manager = $this->addStaff($this->org, 'practice_manager');   // no roles.manage in the template
        $role = $this->roleOf($this->org, 'clinician');

        $this->assertRefused(fn () => $this->actAs($manager, fn () => app(CreateRole::class)('Nope', null, [])), 'forbidden');
        $this->assertRefused(fn () => $this->actAs($manager, fn () => app(UpdateRolePermissions::class)($role, 'Clinician', null, [])), 'forbidden');
        $this->assertRefused(fn () => $this->actAs($manager, fn () => app(DeleteRole::class)($this->makeRole($this->org, 'Spare', []))), 'forbidden');

        // Granting roles.manage opens the boundary; revoking it closes it again.
        $this->grantToRole($this->org, 'practice_manager', 'roles.manage');
        $created = $this->actAs($manager, fn () => app(CreateRole::class)('Now allowed', null, ['team.view']));
        $this->assertNotNull($created->id);

        $this->revokeFromRole($this->org, 'practice_manager', 'roles.manage');
        $this->assertRefused(fn () => $this->actAs($manager, fn () => app(CreateRole::class)('Closed again', null, [])), 'forbidden');
    }

    #[Test]
    public function another_organizations_role_is_not_found(): void
    {
        $other = $this->createOrganization();
        $foreign = $this->roleOf($other->organization, 'clinician');
        $foreignCustom = $this->makeRole($other->organization, 'Theirs', ['team.view']);
        $before = $this->permissionsOfRole($foreign);

        $this->expectException(ModelNotFoundException::class);

        try {
            $this->actAs($this->admin, fn () => app(UpdateRolePermissions::class)($foreign, 'Hijacked', null, ['team.view']));
        } finally {
            $this->assertSame($before, $this->permissionsOfRole($foreign));
            $this->assertSame('Clinician', $foreign->fresh()->name);

            // Deleting a foreign custom role is refused too, and it survives.
            try {
                $this->actAs($this->admin, fn () => app(DeleteRole::class)($foreignCustom));
                $this->fail('A foreign role was deleted.');
            } catch (ModelNotFoundException) {
                $this->assertNotNull(Role::query()->find($foreignCustom->id));
            }
        }
    }

    #[Test]
    public function platform_roles_are_not_found_either(): void
    {
        $platformRole = Role::query()->platform()->where('key', RoleTemplates::SUPER_ADMIN)->firstOrFail();

        $this->expectException(ModelNotFoundException::class);

        $this->actAs($this->admin, fn () => app(UpdateRolePermissions::class)($platformRole, 'Mine now', null, []));
    }

    #[Test]
    public function the_role_policy_tells_screens_what_to_offer(): void
    {
        $custom = $this->makeRole($this->org, 'Custom', ['team.view']);
        $locked = $this->roleOf($this->org, RoleTemplates::ORG_ADMIN);
        $system = $this->roleOf($this->org, 'clinician');
        $foreign = $this->roleOf($this->createOrganization()->organization, 'clinician');
        $clinician = $this->addStaff($this->org, 'clinician');

        $ask = fn (OrganizationMembership $actor, string $ability, $subject = null) => $this->actAs($actor, fn () => Gate::forUser($actor->user)->inspect($ability, $subject ?? Role::class));

        // An administrator: everything on custom and system roles; the locked role is read-only and undeletable.
        $this->assertTrue($ask($this->admin, 'viewAny')->allowed());
        $this->assertTrue($ask($this->admin, 'create')->allowed());
        $this->assertTrue($ask($this->admin, 'view', $locked)->allowed());
        $this->assertTrue($ask($this->admin, 'update', $custom)->allowed());
        $this->assertTrue($ask($this->admin, 'update', $system)->allowed());
        $this->assertTrue($ask($this->admin, 'delete', $custom)->allowed());
        $this->assertFalse($ask($this->admin, 'update', $locked)->allowed());
        $this->assertFalse($ask($this->admin, 'delete', $system)->allowed());
        $this->assertFalse($ask($this->admin, 'delete', $locked)->allowed());

        // Another organization's role does not exist for them.
        $notFound = $ask($this->admin, 'update', $foreign);
        $this->assertTrue($notFound->denied());
        $this->assertSame(404, $notFound->status());

        // Without roles.manage nothing is offered.
        foreach (['viewAny', 'create'] as $ability) {
            $this->assertTrue($ask($clinician, $ability)->denied());
        }
        $this->assertTrue($ask($clinician, 'update', $custom)->denied());
        $this->assertNotSame(404, $ask($clinician, 'update', $custom)->status());   // they know roles exist: a plain 403
    }
}
