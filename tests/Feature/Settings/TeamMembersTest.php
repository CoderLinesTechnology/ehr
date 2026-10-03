<?php

namespace Tests\Feature\Settings;

use App\Domain\Identity\AccessGuard;
use App\Domain\Identity\ChangeMembershipStatus;
use App\Domain\Identity\InviteStaff;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Identity\RevokeInvitation;
use App\Domain\Identity\RoleTemplates;
use App\Domain\Identity\StaffSeats;
use App\Domain\Identity\UpdateMember;
use App\Domain\Platform\CreatedOrganization;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Settings\Concerns\WorksInsideOrganizations;
use Tests\TestCase;

class TeamMembersTest extends TestCase
{
    use WorksInsideOrganizations;

    private CreatedOrganization $created;

    private Organization $org;

    private OrganizationMembership $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->created = $this->createOrganization();
        $this->org = $this->created->organization;
        $this->admin = $this->created->ownerMembership;
    }

    /** @param list<string> $roleKeys */
    private function editMember(OrganizationMembership $actor, OrganizationMembership $target, array $profile = [], ?array $roleKeys = null): OrganizationMembership
    {
        $organization = Organization::query()->findOrFail($target->organization_id);
        $roleIds = array_map(fn (string $key) => $this->roleOf($organization, $key)->id, $roleKeys ?? $this->roleKeysOf($target));

        return $this->actAs($actor, fn () => app(UpdateMember::class)($target, $profile, $roleIds));
    }

    private function setStatus(OrganizationMembership $actor, OrganizationMembership $target, MembershipStatus $to, ?string $reason = null): OrganizationMembership
    {
        return $this->actAs($actor, fn () => app(ChangeMembershipStatus::class)($target, $to, $reason));
    }

    private function holds(OrganizationMembership $member, string $permission): bool
    {
        return $this->actAs($member, fn () => Gate::forUser($member->user)->allows($permission));
    }

    #[Test]
    public function a_manager_edits_a_members_professional_details(): void
    {
        $manager = $this->addStaff($this->org, 'practice_manager');
        $clinician = $this->addStaff($this->org, 'clinician', ['title' => 'Counsellor', 'is_provider' => false, 'color' => null]);

        $this->editMember($manager, $clinician, ['title' => '  Clinical Psychologist ', 'credentials' => 'PsyD, CPsychol', 'is_provider' => true, 'color' => '#3FB68B']);

        $row = $this->reload($clinician);
        $this->assertSame('Clinical Psychologist', $row->title);
        $this->assertSame('PsyD, CPsychol', $row->credentials);
        $this->assertTrue($row->is_provider);
        $this->assertSame('#3fb68b', $row->color);
        $this->assertSame(['clinician'], $this->roleKeysOf($row));

        $audit = $this->lastAudit('team.member_updated', $this->org);
        $this->assertSame('Counsellor', $audit->before['title']);
        $this->assertSame('Clinical Psychologist', $audit->after['title']);
        $this->assertArrayHasKey('is_provider', $audit->after);
        $this->assertSame($manager->user_id, $audit->actor_user_id);
        $this->assertSame(0, $this->auditCount('team.member_roles_changed', $this->org));   // roles were not touched
    }

    #[Test]
    public function blank_details_become_null_and_bad_ones_are_refused(): void
    {
        $clinician = $this->addStaff($this->org, 'clinician', ['title' => 'Counsellor']);

        $this->editMember($this->admin, $clinician, ['title' => '   ', 'credentials' => '']);
        $this->assertNull($this->reload($clinician)->title);

        $this->assertRefused(fn () => $this->editMember($this->admin, $clinician, ['color' => 'red']), 'invalid_color', 'color');
        $this->assertRefused(fn () => $this->editMember($this->admin, $clinician, ['color' => '#12345']), 'invalid_color', 'color');
        $this->assertRefused(fn () => $this->editMember($this->admin, $clinician, ['title' => str_repeat('x', 101)]), 'too_long', 'title');
    }

    #[Test]
    public function changing_roles_applies_immediately_and_is_audited(): void
    {
        $manager = $this->addStaff($this->org, 'practice_manager');
        $clinician = $this->addStaff($this->org, 'clinician');

        $this->assertFalse($this->holds($clinician, 'reports.view'));

        $this->editMember($manager, $clinician, roleKeys: ['clinician', 'billing']);

        $this->assertTrue($this->holds($clinician, 'reports.view'));   // same process: the resolver was flushed
        $this->assertEqualsCanonicalizing(['billing', 'clinician'], $this->roleKeysOf($clinician));

        $audit = $this->lastAudit('team.member_roles_changed', $this->org);
        $this->assertSame(['Clinician'], $audit->before['roles']);
        $this->assertSame(['Billing Staff', 'Clinician'], $audit->after['roles']);
        $this->assertSame(['Billing Staff'], $audit->metadata['added']);
        $this->assertSame([], $audit->metadata['removed']);

        // And back.
        $this->editMember($manager, $clinician, roleKeys: ['clinician']);
        $this->assertFalse($this->holds($clinician, 'reports.view'));
        $this->assertSame(['Billing Staff'], $this->lastAudit('team.member_roles_changed', $this->org)->metadata['removed']);
    }

    #[Test]
    public function a_member_always_keeps_at_least_one_role_from_this_organization(): void
    {
        $clinician = $this->addStaff($this->org, 'clinician');
        $other = $this->createOrganization();
        $foreign = $this->roleOf($other->organization, 'clinician');

        $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(UpdateMember::class)($clinician, [], [])), 'no_roles', 'roles');
        $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(UpdateMember::class)($clinician, [], [$foreign->id])), 'invalid_role', 'roles');

        $this->assertSame(['clinician'], $this->roleKeysOf($clinician));
        $this->assertSame(0, DB::table('membership_roles')->where('role_id', $foreign->id)->count());
    }

    #[Test]
    public function a_manager_cannot_change_the_roles_of_someone_whose_access_exceeds_their_own(): void
    {
        $manager = $this->addStaff($this->org, 'practice_manager');
        $strong = $this->addStaff($this->org, 'clinician');
        $this->giveRole($strong, $this->makeRole($this->org, 'Role Editor', ['roles.manage']));

        // The administrator…
        $this->assertRefused(fn () => $this->editMember($manager, $this->admin, roleKeys: ['clinician']), 'privilege_escalation');
        $this->assertRefused(fn () => $this->editMember($manager, $this->admin, roleKeys: [RoleTemplates::ORG_ADMIN, 'clinician']), 'privilege_escalation');
        // …and a member holding something the manager does not.
        $this->assertRefused(fn () => $this->editMember($manager, $strong, roleKeys: ['staff']), 'privilege_escalation');

        $this->assertSame([RoleTemplates::ORG_ADMIN], $this->roleKeysOf($this->admin));
        $this->assertCount(2, $this->roleKeysOf($strong));
        $this->assertSame(3, $this->auditCount('security.privilege_escalation_blocked', $this->org));

        // Professional details of the same people are not access: still editable.
        $this->editMember($manager, $this->admin, ['title' => 'Director']);
        $this->assertSame('Director', $this->reload($this->admin)->title);
    }

    #[Test]
    public function a_manager_cannot_hand_out_a_role_they_could_not_hold_themselves(): void
    {
        $manager = $this->addStaff($this->org, 'practice_manager');
        $clinician = $this->addStaff($this->org, 'clinician');
        $strong = $this->makeRole($this->org, 'Role Editor', ['roles.manage']);

        $this->assertRefused(fn () => $this->editMember($manager, $clinician, roleKeys: ['clinician', $strong->key]), 'privilege_escalation', 'roles');
        $this->assertRefused(fn () => $this->editMember($manager, $clinician, roleKeys: ['clinician', RoleTemplates::ORG_ADMIN]), 'privilege_escalation', 'roles');
        $this->assertSame(['clinician'], $this->roleKeysOf($clinician));

        // An administrator can.
        $this->editMember($this->admin, $clinician, roleKeys: ['clinician', $strong->key]);
        $this->assertCount(2, $this->roleKeysOf($clinician));
    }

    #[Test]
    public function nobody_can_remove_their_own_administrator_role(): void
    {
        $second = $this->addStaff($this->org, 'org_admin');

        // Even with another administrator around, and even when they keep another role.
        $this->assertRefused(fn () => $this->editMember($this->admin, $this->admin, roleKeys: ['clinician']), 'self_admin_role', 'roles');
        $this->assertRefused(fn () => $this->editMember($second, $second, roleKeys: ['staff']), 'self_admin_role', 'roles');

        $this->assertSame([RoleTemplates::ORG_ADMIN], $this->roleKeysOf($this->admin));
        $this->assertSame([RoleTemplates::ORG_ADMIN], $this->roleKeysOf($second));
    }

    #[Test]
    public function one_administrator_can_demote_another_while_one_remains(): void
    {
        $second = $this->addStaff($this->org, 'org_admin');

        $this->editMember($this->admin, $second, roleKeys: ['practice_manager']);

        $this->assertSame(['practice_manager'], $this->roleKeysOf($second));
        $this->assertSame([RoleTemplates::ORG_ADMIN], $this->roleKeysOf($this->admin));
        $this->assertSame(['Organization Administrator'], $this->lastAudit('team.member_roles_changed', $this->org)->before['roles']);
    }

    #[Test]
    public function an_organization_always_keeps_an_active_administrator(): void
    {
        $guard = fn (OrganizationMembership $member) => fn () => $this->actAs($this->admin, fn () => app(AccessGuard::class)->assertKeepsAnAdministrator($member));

        // The rule itself: the only active administrator cannot leave…
        $this->assertRefused($guard($this->admin), 'last_admin');

        // …a suspended administrator does not count as a successor…
        $second = $this->addStaff($this->org, 'org_admin', ['status' => MembershipStatus::Suspended]);
        $this->assertRefused($guard($this->admin), 'last_admin');

        // …an active one does, and then either may leave. Someone who is not an administrator never triggers it.
        $this->inTenant($this->org, fn () => $this->reload($second)->forceFill(['status' => MembershipStatus::Active])->save());
        $guard($this->admin)();
        $guard($second)();
        $guard($this->addStaff($this->org, 'clinician'))();
        $this->assertTrue(true);
    }

    #[Test]
    public function the_last_administrator_cannot_be_deactivated_by_anyone(): void
    {
        $manager = $this->addStaff($this->org, 'practice_manager');

        // By themselves (a self rule) and by someone who is not an administrator (the rank rule).
        $this->assertRefused(fn () => $this->setStatus($this->admin, $this->admin, MembershipStatus::Deactivated), 'self_action');
        $this->assertRefused(fn () => $this->setStatus($manager, $this->admin, MembershipStatus::Suspended), 'privilege_escalation');

        $this->assertSame(MembershipStatus::Active, $this->reload($this->admin)->status);
    }

    #[Test]
    public function members_are_suspended_deactivated_and_reactivated_with_an_audit_trail(): void
    {
        $clinician = $this->addStaff($this->org, 'clinician');
        $this->assertTrue($this->holds($clinician, 'clients.create'));

        $this->setStatus($this->admin, $clinician, MembershipStatus::Suspended, '  Pending an investigation  ');

        $row = $this->reload($clinician);
        $this->assertSame(MembershipStatus::Suspended, $row->status);
        $this->assertNull($row->deactivated_at);
        $this->assertFalse($this->holds($row, 'clients.create'));   // access ends at once
        $audit = $this->lastAudit('team.member_suspended', $this->org);
        $this->assertSame(['status' => 'active'], $audit->before);
        $this->assertSame(['status' => 'suspended'], $audit->after);
        $this->assertSame('Pending an investigation', $audit->metadata['reason']);
        $this->assertSame($this->admin->user_id, $audit->actor_user_id);

        $this->setStatus($this->admin, $clinician, MembershipStatus::Deactivated);
        $row = $this->reload($clinician);
        $this->assertSame(MembershipStatus::Deactivated, $row->status);
        $this->assertNotNull($row->deactivated_at);
        $this->assertNotNull($this->lastAudit('team.member_deactivated', $this->org));

        $this->setStatus($this->admin, $clinician, MembershipStatus::Active);
        $row = $this->reload($clinician);
        $this->assertSame(MembershipStatus::Active, $row->status);
        $this->assertNull($row->deactivated_at);
        $this->assertTrue($this->holds($row, 'clients.create'));
        $this->assertSame(['status' => 'deactivated'], $this->lastAudit('team.member_reactivated', $this->org)->before);

        // Nothing was deleted: the roles and the user are still there.
        $this->assertSame(['clinician'], $this->roleKeysOf($row));
        $this->assertSame($clinician->user_id, $row->user_id);
    }

    #[Test]
    public function the_instance_a_caller_passes_in_is_left_up_to_date(): void
    {
        $clinician = $this->addStaff($this->org, 'clinician');

        $result = $this->setStatus($this->admin, $clinician, MembershipStatus::Suspended);

        $this->assertSame(MembershipStatus::Suspended, $clinician->status);
        $this->assertSame($clinician->id, $result->id);
    }

    #[Test]
    public function repeating_a_status_change_does_nothing_and_audits_nothing(): void
    {
        $clinician = $this->addStaff($this->org, 'clinician');

        $this->setStatus($this->admin, $clinician, MembershipStatus::Suspended);
        $this->setStatus($this->admin, $clinician, MembershipStatus::Suspended);   // a double submit

        $this->assertSame(1, $this->auditCount('team.member_suspended', $this->org));
        $this->assertSame(MembershipStatus::Suspended, $this->reload($clinician)->status);
    }

    #[Test]
    public function only_sensible_transitions_are_allowed(): void
    {
        $clinician = $this->addStaff($this->org, 'clinician');
        $this->setStatus($this->admin, $clinician, MembershipStatus::Deactivated);

        $this->assertRefused(fn () => $this->setStatus($this->admin, $clinician, MembershipStatus::Suspended), 'invalid_transition');
        $this->assertRefused(fn () => $this->setStatus($this->admin, $clinician, MembershipStatus::Invited), 'invalid_transition');

        // An invitation that has not been accepted is revoked, not suspended.
        $this->assertSame(MembershipStatus::Deactivated, $this->reload($clinician)->status);
        $invite = $this->actAs($this->admin, fn () => app(InviteStaff::class)('x@example.com', [$this->roleOf($this->org, 'clinician')->id]));
        $this->assertRefused(fn () => $this->setStatus($this->admin, $invite, MembershipStatus::Suspended), 'still_invited');
        $this->assertSame(MembershipStatus::Invited, $this->reload($invite)->status);
    }

    #[Test]
    public function nobody_can_suspend_or_deactivate_themselves(): void
    {
        $manager = $this->addStaff($this->org, 'practice_manager');

        foreach ([MembershipStatus::Suspended, MembershipStatus::Deactivated] as $to) {
            $this->assertRefused(fn () => $this->setStatus($manager, $manager, $to), 'self_action');
            $this->assertRefused(fn () => $this->setStatus($this->admin, $this->admin, $to), 'self_action');
        }

        $this->assertSame(MembershipStatus::Active, $this->reload($manager)->status);
        $this->assertSame(MembershipStatus::Active, $this->reload($this->admin)->status);
        $this->assertSame(0, $this->auditCount('team.member_suspended', $this->org) + $this->auditCount('team.member_deactivated', $this->org));
    }

    #[Test]
    public function a_manager_can_manage_those_below_them_but_not_administrators_or_stronger_members(): void
    {
        $manager = $this->addStaff($this->org, 'practice_manager');
        $clinician = $this->addStaff($this->org, 'clinician');
        $strong = $this->addStaff($this->org, 'clinician');
        $this->giveRole($strong, $this->makeRole($this->org, 'Role Editor', ['roles.manage']));
        $peer = $this->addStaff($this->org, 'practice_manager');

        $this->setStatus($manager, $clinician, MembershipStatus::Suspended);   // below them: fine
        $this->setStatus($manager, $peer, MembershipStatus::Deactivated);      // an equal: fine (same permissions)
        $this->assertRefused(fn () => $this->setStatus($manager, $this->admin, MembershipStatus::Suspended), 'privilege_escalation');
        $this->assertRefused(fn () => $this->setStatus($manager, $strong, MembershipStatus::Deactivated), 'privilege_escalation');

        $this->assertSame(MembershipStatus::Suspended, $this->reload($clinician)->status);
        $this->assertSame(MembershipStatus::Active, $this->reload($this->admin)->status);
        $this->assertSame(MembershipStatus::Active, $this->reload($strong)->status);

        // An administrator can manage anyone (except themselves).
        $this->setStatus($this->admin, $strong, MembershipStatus::Suspended);
        $this->assertSame(MembershipStatus::Suspended, $this->reload($strong)->status);
    }

    #[Test]
    public function seats_are_held_by_active_and_invited_staff_and_reactivating_takes_one(): void
    {
        $starter = $this->createOrganization(plan: 'starter');   // 3 seats
        $organization = $starter->organization;
        $admin = $starter->ownerMembership;
        $a = $this->addStaff($organization, 'clinician');
        $b = $this->addStaff($organization, 'clinician');
        $seats = fn () => $this->actAs($admin, fn () => app(StaffSeats::class)->inUse());

        $this->assertSame(3, $seats());

        $this->setStatus($admin, $b, MembershipStatus::Suspended);
        $this->assertSame(2, $seats());   // a suspended member holds no seat

        $role = $this->roleOf($organization, 'clinician');
        $invite = $this->actAs($admin, fn () => app(InviteStaff::class)('new@example.com', [$role->id]));
        $this->assertSame(3, $seats());

        // The seat went to the invitation, so b cannot come back yet.
        $e = $this->assertRefused(fn () => $this->setStatus($admin, $b, MembershipStatus::Active), 'limit_reached');
        $this->assertStringContainsString('3 staff seats', $e->userMessage());
        $this->assertSame(MembershipStatus::Suspended, $this->reload($b)->status);

        $this->actAs($admin, fn () => app(RevokeInvitation::class)($invite));
        $this->setStatus($admin, $b, MembershipStatus::Active);
        $this->assertSame(MembershipStatus::Active, $this->reload($b)->status);
        $this->assertSame(3, $seats());
        $this->assertSame(MembershipStatus::Active, $this->reload($a)->status);
    }

    #[Test]
    public function only_someone_who_manages_the_team_can_edit_or_change_status(): void
    {
        $clinician = $this->addStaff($this->org, 'clinician');
        $manager = $this->addStaff($this->org, 'practice_manager');
        $target = $this->addStaff($this->org, 'staff');

        $this->assertRefused(fn () => $this->setStatus($clinician, $target, MembershipStatus::Suspended), 'forbidden');
        $this->assertRefused(fn () => $this->editMember($clinician, $target, ['title' => 'Boss']), 'forbidden');

        $this->setStatus($manager, $target, MembershipStatus::Suspended);   // allowed…

        $this->revokeFromRole($this->org, 'practice_manager', 'team.manage');   // …until the permission is taken away
        $this->assertRefused(fn () => $this->setStatus($manager, $target, MembershipStatus::Active), 'forbidden');
        $this->assertRefused(fn () => $this->editMember($manager, $target, ['title' => 'Boss']), 'forbidden');
        $this->assertSame(MembershipStatus::Suspended, $this->reload($target)->status);
    }

    #[Test]
    public function another_organizations_member_is_not_found_and_left_untouched(): void
    {
        $other = $this->createOrganization();
        $foreign = $this->addStaff($other->organization, 'clinician', ['title' => 'Theirs']);

        $attempts = [
            fn () => $this->setStatus($this->admin, $foreign, MembershipStatus::Suspended),
            fn () => $this->actAs($this->admin, fn () => app(UpdateMember::class)($foreign, ['title' => 'Hijacked'], [$this->roleOf($this->org, 'org_admin')->id])),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('An action reached a member of another organization.');
            } catch (ModelNotFoundException) {
                // expected
            }
        }

        $row = $this->reload($foreign);
        $this->assertSame(MembershipStatus::Active, $row->status);
        $this->assertSame('Theirs', $row->title);
        $this->assertSame(['clinician'], $this->roleKeysOf($row));
        $this->assertSame(0, $this->auditCount('team.member_suspended') + $this->auditCount('team.member_updated'));
        $this->assertSame(0, $this->auditCount('security.privilege_escalation_blocked'));   // refused before any rule ran, so nothing leaked into an audit log
    }
}
