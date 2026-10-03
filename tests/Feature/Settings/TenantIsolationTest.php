<?php

namespace Tests\Feature\Settings;

use App\Domain\Identity\ChangeMembershipStatus;
use App\Domain\Identity\DeleteRole;
use App\Domain\Identity\InviteStaff;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Identity\ResendInvitation;
use App\Domain\Identity\RevokeInvitation;
use App\Domain\Identity\RoleDirectory;
use App\Domain\Identity\TeamDirectory;
use App\Domain\Identity\UpdateMember;
use App\Domain\Identity\UpdateRolePermissions;
use App\Domain\Organization\ChangeLocationStatus;
use App\Domain\Organization\ChangeServiceStatus;
use App\Domain\Organization\SaveLocation;
use App\Domain\Organization\SaveService;
use App\Domain\Organization\UpdateOrganizationProfile;
use App\Domain\Platform\CreatedOrganization;
use App\Domain\Shared\DomainException;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Settings\Concerns\WorksInsideOrganizations;
use Tests\TestCase;

/**
 * Organization A's administrator is handed organization B's records (as models, as ids inside forms)
 * through every settings, team and role action. Each attempt must be refused, and nothing in either
 * organization may change: not a row, not an audit entry, not an invitation email.
 */
class TenantIsolationTest extends TestCase
{
    use WorksInsideOrganizations;

    private CreatedOrganization $a;

    private CreatedOrganization $b;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->a = $this->createOrganization(['name' => 'Organization A']);
        $this->b = $this->createOrganization(['name' => 'Organization B']);
    }

    /** A fingerprint of everything an organization owns in the tables these actions write. */
    private function snapshot(Organization $organization): string
    {
        $id = $organization->id;
        $roleIds = DB::table('roles')->where('organization_id', $id)->pluck('id');

        return md5(json_encode([
            DB::table('organizations')->where('id', $id)->get(),
            DB::table('organization_memberships')->where('organization_id', $id)->orderBy('id')->get(),
            DB::table('membership_roles')->where('organization_id', $id)->orderBy('membership_id')->orderBy('role_id')->get(),
            DB::table('roles')->where('organization_id', $id)->orderBy('id')->get(),
            DB::table('role_permissions')->whereIn('role_id', $roleIds)->orderBy('role_id')->orderBy('permission_key')->get(),
            DB::table('locations')->where('organization_id', $id)->orderBy('id')->get(),
            DB::table('services')->where('organization_id', $id)->orderBy('id')->get(),
            DB::table('service_providers')->where('organization_id', $id)->orderBy('service_id')->orderBy('membership_id')->get(),
            DB::table('service_locations')->where('organization_id', $id)->orderBy('service_id')->orderBy('location_id')->get(),
            DB::table('organization_settings')->where('organization_id', $id)->orderBy('key')->get(),
            DB::table('audit_logs')->where('organization_id', $id)->orderBy('id')->get(),
        ]));
    }

    #[Test]
    public function every_action_refuses_the_other_organizations_records_and_changes_nothing(): void
    {
        $adminA = $this->a->ownerMembership;
        $orgB = $this->b->organization;

        // Organization B's records, created through the domain as its own administrator would.
        $locationB = $this->actAs($this->b->ownerMembership, fn () => app(SaveLocation::class)(['name' => 'B Clinic']));
        $serviceB = $this->actAs($this->b->ownerMembership, fn () => app(SaveService::class)([
            'name' => 'B Therapy', 'duration_minutes' => 50, 'price' => '100', 'allows_in_person' => true, 'billing_behavior' => 'billable',
            'provider_ids' => [$this->b->ownerMembership->id], 'location_ids' => [$locationB->id],
        ]));
        $memberB = $this->addStaff($orgB, 'clinician');
        $inviteB = $this->actAs($this->b->ownerMembership, fn () => app(InviteStaff::class)('b.invite@example.com', [$this->roleOf($orgB, 'staff')->id]));
        $roleB = $this->makeRole($orgB, 'B Custom', ['team.view']);
        $systemRoleB = $this->roleOf($orgB, 'clinician');

        // And a couple of A's own, so forms can be handed B's ids next to legitimate ones.
        $serviceA = $this->actAs($adminA, fn () => app(SaveService::class)([
            'name' => 'A Therapy', 'duration_minutes' => 50, 'price' => '100', 'allows_in_person' => true, 'billing_behavior' => 'billable',
        ]));
        $memberA = $this->addStaff($this->a->organization, 'clinician');
        $service = ['name' => 'X', 'duration_minutes' => 30, 'price' => '10', 'allows_in_person' => true, 'billing_behavior' => 'billable'];

        $attempts = [
            'edit B location' => fn () => app(SaveLocation::class)(['name' => 'Hijacked'], $locationB),
            'deactivate B location' => fn () => app(ChangeLocationStatus::class)($locationB, false),
            'edit B service' => fn () => app(SaveService::class)(['name' => 'Hijacked'] + $service, $serviceB),
            'deactivate B service' => fn () => app(ChangeServiceStatus::class)($serviceB, false),
            'new service with B provider' => fn () => app(SaveService::class)($service + ['provider_ids' => [$this->b->ownerMembership->id]]),
            'new service at B location' => fn () => app(SaveService::class)($service + ['location_ids' => [$locationB->id]]),
            'edit A service with B provider' => fn () => app(SaveService::class)(['name' => 'A Therapy'] + $service + ['provider_ids' => [$memberB->id]], $serviceA),
            'edit A service at B location' => fn () => app(SaveService::class)(['name' => 'A Therapy'] + $service + ['location_ids' => [$locationB->id]], $serviceA),
            'invite with B role' => fn () => app(InviteStaff::class)('new@example.com', [$roleB->id]),
            'invite with B system role' => fn () => app(InviteStaff::class)('new@example.com', [$this->roleOf($this->a->organization, 'clinician')->id, $systemRoleB->id]),
            'edit B member' => fn () => app(UpdateMember::class)($memberB, ['title' => 'Hijacked'], [$this->roleOf($this->a->organization, 'org_admin')->id]),
            'give A member a B role' => fn () => app(UpdateMember::class)($memberA, [], [$systemRoleB->id]),
            'suspend B member' => fn () => app(ChangeMembershipStatus::class)($memberB, MembershipStatus::Suspended),
            'resend B invitation' => fn () => app(ResendInvitation::class)($inviteB),
            'revoke B invitation' => fn () => app(RevokeInvitation::class)($inviteB),
            'change B role' => fn () => app(UpdateRolePermissions::class)($roleB, 'Hijacked', null, ['clients.view_all']),
            'change B system role' => fn () => app(UpdateRolePermissions::class)($systemRoleB, 'Hijacked', null, []),
            'delete B role' => fn () => app(DeleteRole::class)($roleB),
            'edit B profile' => fn () => app(UpdateOrganizationProfile::class)($orgB, [
                'name' => 'Hijacked', 'country_code' => 'GH', 'timezone' => 'Africa/Accra', 'currency' => 'GHS', 'locale' => 'en',
            ]),
        ];

        $beforeA = $this->snapshot($this->a->organization);
        $beforeB = $this->snapshot($orgB);
        $mails = count($this->sentInvitations());
        $refused = 0;

        foreach ($attempts as $label => $attempt) {
            try {
                $this->actAs($adminA, $attempt);
                $this->fail("[{$label}] reached another organization's data.");
            } catch (ModelNotFoundException|DomainException) {
                $refused++;
            }
        }

        $this->assertSame(count($attempts), $refused);
        $this->assertSame($beforeB, $this->snapshot($orgB), 'Organization B changed.');
        $this->assertSame($mails, count($this->sentInvitations()), 'An invitation email went out.');
        $this->assertSame($beforeA, $this->snapshot($this->a->organization), 'Organization A changed.');
    }

    #[Test]
    public function the_database_itself_refuses_cross_organization_links(): void
    {
        $locationB = $this->inTenant($this->b->organization, fn () => Location::factory()->create());
        $memberB = $this->addStaff($this->b->organization, 'clinician');
        $roleB = $this->roleOf($this->b->organization, 'clinician');
        $serviceA = $this->inTenant($this->a->organization, fn () => Service::factory()->create());
        $memberA = $this->addStaff($this->a->organization, 'clinician');

        $writes = [
            'provider from another organization' => fn () => DB::table('service_providers')->insert(['organization_id' => $this->a->organization->id, 'service_id' => $serviceA->id, 'membership_id' => $memberB->id]),
            'location from another organization' => fn () => DB::table('service_locations')->insert(['organization_id' => $this->a->organization->id, 'service_id' => $serviceA->id, 'location_id' => $locationB->id]),
            'role from another organization' => fn () => DB::table('membership_roles')->insert(['organization_id' => $this->a->organization->id, 'membership_id' => $memberA->id, 'role_id' => $roleB->id]),
            'platform permission on an organization role' => fn () => DB::table('role_permissions')->insert(['role_id' => $this->roleOf($this->a->organization, 'clinician')->id, 'permission_key' => 'platform.settings.manage', 'scope' => 'organization']),
        ];

        foreach ($writes as $label => $write) {
            try {
                DB::transaction($write);
                $this->fail("The database accepted: {$label}.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('violates foreign key constraint', $e->getMessage(), $label);
            }
        }
    }

    #[Test]
    public function the_read_models_only_ever_show_the_current_organization(): void
    {
        $this->addStaff($this->b->organization, 'clinician');
        $this->makeRole($this->b->organization, 'B Secret Role', []);

        $team = $this->inTenant($this->a->organization, fn () => app(TeamDirectory::class)->paginate()->pluck('organization_id')->unique()->all());
        $roles = app(RoleDirectory::class)->overview($this->a->organization->id);

        $this->assertSame([$this->a->organization->id], $team);
        $this->assertNotContains('B Secret Role', $roles->pluck('name')->all());
        $this->assertSame([$this->a->organization->id], $roles->pluck('organization_id')->unique()->all());
    }

    #[Test]
    public function an_action_refuses_to_run_without_an_organization_or_an_acting_member(): void
    {
        $role = $this->roleOf($this->a->organization, 'clinician');

        // No organization and nobody acting: refused, nothing written.
        $this->assertRefused(fn () => app(InviteStaff::class)('x@example.com', [$role->id]), 'forbidden');

        // An organization but nobody acting inside it (a job, a console command): refused the same way.
        $this->inTenant($this->a->organization, function () use ($role) {
            $this->assertRefused(fn () => app(InviteStaff::class)('x@example.com', [$role->id]), 'forbidden');
            $this->assertRefused(fn () => app(SaveLocation::class)(['name' => 'Nowhere']), 'forbidden');
            $this->assertRefused(fn () => app(DeleteRole::class)($role), 'forbidden');
        });

        $this->assertSame(1, OrganizationMembership::acrossTenants()->where('organization_id', $this->a->organization->id)->count());
        $this->assertSame(0, Location::query()->withoutGlobalScopes()->where('organization_id', $this->a->organization->id)->count());
        $this->assertSame([], $this->sentInvitations());
    }
}
