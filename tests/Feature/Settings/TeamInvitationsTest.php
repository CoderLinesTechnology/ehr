<?php

namespace Tests\Feature\Settings;

use App\Domain\Identity\AccessGuard;
use App\Domain\Identity\Events\MembershipInvited;
use App\Domain\Identity\FindInvitation;
use App\Domain\Identity\InvitationTokens;
use App\Domain\Identity\InviteStaff;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Identity\ResendInvitation;
use App\Domain\Identity\RevokeInvitation;
use App\Domain\Identity\RoleTemplates;
use App\Domain\Identity\StaffSeats;
use App\Domain\Platform\CreatedOrganization;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Settings\Concerns\WorksInsideOrganizations;
use Tests\TestCase;

class TeamInvitationsTest extends TestCase
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

    private function invite(string $email, array $roleKeys = ['clinician'], ?OrganizationMembership $as = null, array $extra = []): OrganizationMembership
    {
        $actor = $as ?? $this->admin;
        $organization = Organization::query()->findOrFail($actor->organization_id);
        $roleIds = array_map(fn (string $key) => $this->roleOf($organization, $key)->id, $roleKeys);

        return $this->actAs($actor, fn () => app(InviteStaff::class)($email, $roleIds, ...$extra));
    }

    #[Test]
    public function an_administrator_invites_staff_by_email(): void
    {
        $invite = $this->invite('  Jane.Doe@Example.COM ', ['clinician', 'staff'], extra: ['title' => '  Clinical Psychologist ', 'isProvider' => true, 'credentials' => 'PsyD']);

        $row = $this->reload($invite);
        $this->assertSame(MembershipStatus::Invited, $row->status);
        $this->assertSame('jane.doe@example.com', $row->invited_email);
        $this->assertNull($row->user_id);
        $this->assertNull($row->joined_at);
        $this->assertSame($this->org->id, $row->organization_id);
        $this->assertSame($this->admin->user_id, $row->invited_by_user_id);
        $this->assertSame('Clinical Psychologist', $row->title);
        $this->assertSame('PsyD', $row->credentials);
        $this->assertTrue($row->is_provider);
        $this->assertEqualsCanonicalizing(['clinician', 'staff'], $this->roleKeysOf($row));

        // Seven days to accept.
        $this->assertEqualsWithDelta(now()->addDays(InvitationTokens::VALID_DAYS)->getTimestamp(), $row->invitation_expires_at->getTimestamp(), 5);

        // One email, to that address, carrying a link built from the path and the token that hashes to the stored value.
        $sent = $this->sentInvitations();
        $this->assertCount(1, $sent);
        $mail = $sent[0];
        $this->assertSame('jane.doe@example.com', $mail['email']);
        $this->assertSame(url('/invitations/'.$mail['token']), $mail['url']);
        $this->assertSame(64, strlen($mail['token']));
        $this->assertSame(hash('sha256', $mail['token']), $row->invitation_token_hash);
        $this->assertSame($this->org->name, $mail['notification']->organizationName);
        $this->assertSame($this->admin->user()->first()->name, $mail['notification']->inviterName);
        $this->assertSame(InvitationTokens::VALID_DAYS, $mail['notification']->validDays);

        $audit = $this->lastAudit('team.member_invited', $this->org);
        $this->assertSame('jane.doe@example.com', $audit->after['email']);
        $this->assertSame(['Clinician', 'Staff'], $audit->after['roles']);
        $this->assertSame($this->admin->user_id, $audit->actor_user_id);
        $this->assertSame('membership', $audit->subject_type);
        $this->assertSame($row->id, $audit->subject_id);
    }

    #[Test]
    public function the_plain_token_is_never_stored_or_audited(): void
    {
        $this->invite('jane@example.com');
        $token = $this->lastInvitation()['token'];

        $stored = json_encode([
            DB::table('organization_memberships')->get(),
            DB::table('audit_logs')->get(),
            DB::table('organization_settings')->get(),
        ]);

        $this->assertStringNotContainsString($token, $stored);
        $this->assertStringContainsString(hash('sha256', $token), $stored);   // the hash is what is kept
    }

    #[Test]
    public function an_invitation_announces_itself_with_ids_only_and_a_refused_one_does_not(): void
    {
        Event::fake([MembershipInvited::class]);

        $invite = $this->invite('jane@example.com');

        Event::assertDispatchedTimes(MembershipInvited::class, 1);
        Event::assertDispatched(MembershipInvited::class, fn (MembershipInvited $event) => $event->organizationId === $this->org->id
            && $event->membershipId === $invite->id
            && $event->invitedByUserId === $this->admin->user_id);
        $this->assertSame(['organizationId', 'membershipId', 'invitedByUserId'], array_keys(get_object_vars(new MembershipInvited('a', 'b', 'c'))));   // no token, no email

        $this->assertRefused(fn () => $this->invite('jane@example.com'), 'already_invited');   // refused: no second event
        Event::assertDispatchedTimes(MembershipInvited::class, 1);
    }

    #[Test]
    public function an_invitation_that_is_refused_sends_no_email_and_leaves_nothing_behind(): void
    {
        $this->assertRefused(fn () => $this->invite('not-an-email'), 'invalid_email', 'email');

        $this->assertSame([], $this->sentInvitations());
        $this->assertSame(1, OrganizationMembership::acrossTenants()->where('organization_id', $this->org->id)->count());   // only the owner
        $this->assertSame(0, $this->auditCount('team.member_invited', $this->org));
    }

    #[Test]
    public function invitations_count_against_the_staff_limit_and_revoking_frees_the_seat(): void
    {
        $starter = $this->createOrganization(plan: 'starter');   // max_staff = 3, the owner holds one
        $admin = $starter->ownerMembership;

        $first = $this->invite('a@example.com', as: $admin);
        $this->invite('b@example.com', as: $admin);

        $e = $this->assertRefused(fn () => $this->invite('c@example.com', as: $admin), 'limit_reached');
        $this->assertStringContainsString('3 staff seats', $e->userMessage());
        $this->assertSame(3, $this->actAs($admin, fn () => app(StaffSeats::class)->inUse()));

        $this->actAs($admin, fn () => app(RevokeInvitation::class)($first));
        $this->assertSame(2, $this->actAs($admin, fn () => app(StaffSeats::class)->inUse()));

        $this->invite('c@example.com', as: $admin);   // fits now
        $this->assertSame(3, $this->actAs($admin, fn () => app(StaffSeats::class)->inUse()));
    }

    #[Test]
    public function one_pending_invitation_per_email_ignoring_case(): void
    {
        $this->invite('jane@example.com');

        $e = $this->assertRefused(fn () => $this->invite('JANE@example.com'), 'already_invited', 'email');
        $this->assertStringContainsString('already pending', $e->userMessage());

        $this->assertSame(1, OrganizationMembership::acrossTenants()->where('organization_id', $this->org->id)->whereRaw('lower(invited_email) = ?', ['jane@example.com'])->count());
        $this->assertCount(1, $this->sentInvitations());
    }

    #[Test]
    public function an_expired_invitation_still_holds_the_slot_until_it_is_resent_or_revoked(): void
    {
        $this->invite('jane@example.com');
        $this->travel(8)->days();

        $e = $this->assertRefused(fn () => $this->invite('jane@example.com'), 'already_invited');
        $this->assertStringContainsString('Resend or revoke', $e->userMessage());
    }

    #[Test]
    public function someone_already_on_the_team_cannot_be_invited_again(): void
    {
        $member = $this->addStaff($this->org, 'clinician');
        $email = $member->user->email;

        $e = $this->assertRefused(fn () => $this->invite(strtoupper($email)), 'already_member', 'email');
        $this->assertStringContainsString('already on your team', $e->userMessage());

        foreach ([MembershipStatus::Suspended, MembershipStatus::Deactivated] as $status) {
            $this->inTenant($this->org, fn () => $this->reload($member)->forceFill(['status' => $status])->save());
            $e = $this->assertRefused(fn () => $this->invite($email), 'already_member', 'email');
            $this->assertStringContainsString($status->value, $e->userMessage());
            $this->assertStringContainsString('Reactivate', $e->userMessage());
        }

        $this->assertSame([], $this->sentInvitations());
    }

    #[Test]
    public function a_person_in_another_organization_can_be_invited_here_and_pending_invites_there_do_not_block(): void
    {
        $other = $this->createOrganization();
        $member = $this->addStaff($other->organization, 'clinician');

        $this->invite($member->user->email);                          // an existing user, member elsewhere
        $this->invite('shared@example.com');
        $this->invite('shared@example.com', as: $other->ownerMembership);   // same address pending in a second organization

        $this->assertCount(3, $this->sentInvitations());
    }

    #[Test]
    public function an_invitation_needs_a_valid_email_and_at_least_one_role_from_this_organization(): void
    {
        $role = $this->roleOf($this->org, 'clinician');
        $other = $this->createOrganization();
        $foreign = $this->roleOf($other->organization, 'clinician');

        $invite = fn (string $email, array $roleIds) => fn () => $this->actAs($this->admin, fn () => app(InviteStaff::class)($email, $roleIds));

        $this->assertRefused($invite('nobody', [$role->id]), 'invalid_email', 'email');
        $this->assertRefused($invite('nobody@', [$role->id]), 'invalid_email', 'email');
        $this->assertRefused($invite('a@example.com', []), 'no_roles', 'roles');
        $this->assertRefused($invite('a@example.com', [$foreign->id]), 'invalid_role', 'roles');
        $this->assertRefused($invite('a@example.com', [$role->id, $foreign->id]), 'invalid_role', 'roles');
        $this->assertRefused($invite('a@example.com', [(string) Str::uuid()]), 'invalid_role', 'roles');
        $this->assertRefused($invite('a@example.com', ["' OR 1=1 --"]), 'invalid_role', 'roles');

        $this->assertSame([], $this->sentInvitations());
        $this->assertSame(1, OrganizationMembership::acrossTenants()->where('organization_id', $this->org->id)->count());
    }

    #[Test]
    public function only_someone_who_manages_the_team_can_invite(): void
    {
        $clinician = $this->addStaff($this->org, 'clinician');   // team.view, not team.manage
        $manager = $this->addStaff($this->org, 'practice_manager');

        $this->assertRefused(fn () => $this->invite('a@example.com', as: $clinician), 'forbidden');

        $this->invite('a@example.com', as: $manager);   // allowed

        $this->revokeFromRole($this->org, 'practice_manager', 'team.manage');
        $this->assertRefused(fn () => $this->invite('b@example.com', as: $manager), 'forbidden');

        $this->assertCount(1, $this->sentInvitations());
    }

    #[Test]
    public function a_manager_can_invite_into_roles_no_more_powerful_than_their_own_but_never_an_administrator(): void
    {
        $manager = $this->addStaff($this->org, 'practice_manager');
        $strong = $this->makeRole($this->org, 'Role Editor', ['roles.manage']);   // beyond what a practice manager holds

        // Every built-in role except the administrator is within a practice manager's reach.
        $assignable = $this->actAs($manager, fn () => app(AccessGuard::class)->manageableRoleIds(Role::query()->forOrganization($this->org->id)->get()));
        $builtIn = Role::query()->forOrganization($this->org->id)->where('is_system', true)->where('key', '!=', RoleTemplates::ORG_ADMIN)->pluck('id')->all();
        $this->assertEqualsCanonicalizing($builtIn, $assignable);

        $this->invite('ok@example.com', ['supervisor', 'billing'], as: $manager);

        $this->assertRefused(fn () => $this->invite('boss@example.com', [RoleTemplates::ORG_ADMIN], as: $manager), 'privilege_escalation', 'roles');
        $this->assertRefused(fn () => $this->invite('boss@example.com', ['clinician', $strong->key], as: $manager), 'privilege_escalation', 'roles');

        $this->assertNull(OrganizationMembership::acrossTenants()->where('invited_email', 'boss@example.com')->first());
        $this->assertSame(2, $this->auditCount('security.privilege_escalation_blocked', $this->org));

        // An administrator can invite another administrator.
        $this->invite('second-admin@example.com', [RoleTemplates::ORG_ADMIN]);
        $this->assertCount(2, $this->sentInvitations());
    }

    #[Test]
    public function resending_replaces_the_token_extends_the_expiry_and_kills_the_old_link(): void
    {
        $invite = $this->invite('jane@example.com');
        $first = $this->lastInvitation()['token'];

        $this->travel(3)->days();
        $this->actAs($this->admin, fn () => app(ResendInvitation::class)($invite));
        $second = $this->lastInvitation()['token'];

        $this->assertNotSame($first, $second);
        $this->assertCount(2, $this->sentInvitations());

        $row = $this->reload($invite);
        $this->assertSame(hash('sha256', $second), $row->invitation_token_hash);
        $this->assertEqualsWithDelta(now()->addDays(InvitationTokens::VALID_DAYS)->getTimestamp(), $row->invitation_expires_at->getTimestamp(), 5);

        $find = app(FindInvitation::class);
        $this->assertNull($find($first));
        $this->assertSame($row->id, $find($second)->id);

        $audit = $this->lastAudit('team.invitation_resent', $this->org);
        $this->assertSame('jane@example.com', $audit->metadata['email']);
        $this->assertStringNotContainsString($second, json_encode($audit->toArray()));
    }

    #[Test]
    public function an_invitation_cannot_be_sent_again_within_a_minute(): void
    {
        $invite = $this->invite('jane@example.com');
        $first = $this->lastInvitation()['token'];
        $resend = fn () => $this->actAs($this->admin, fn () => app(ResendInvitation::class)($invite));

        // A double click, or a hammered resend button: refused, no second email, the link unchanged.
        $e = $this->assertRefused($resend, 'resend_too_soon');
        $this->assertStringContainsString('Wait a minute', $e->userMessage());
        $this->travel(30)->seconds();
        $this->assertRefused($resend, 'resend_too_soon');
        $this->assertCount(1, $this->sentInvitations());
        $this->assertSame(hash('sha256', $first), $this->reload($invite)->invitation_token_hash);

        $this->travel(31)->seconds();   // 61 seconds since it was sent
        $resend();
        $this->assertCount(2, $this->sentInvitations());

        // The minute starts again from that send.
        $this->assertRefused($resend, 'resend_too_soon');
        $this->assertCount(2, $this->sentInvitations());
    }

    #[Test]
    public function an_expired_invitation_can_be_resent_and_works_again(): void
    {
        $invite = $this->invite('jane@example.com');
        $old = $this->lastInvitation()['token'];

        $this->travel(8)->days();
        $this->assertNull(app(FindInvitation::class)($old));

        $this->actAs($this->admin, fn () => app(ResendInvitation::class)($invite));

        $this->assertNotNull(app(FindInvitation::class)($this->lastInvitation()['token']));
        $this->assertNull(app(FindInvitation::class)($old));
    }

    #[Test]
    public function only_pending_invitations_can_be_resent_or_revoked(): void
    {
        $member = $this->addStaff($this->org, 'clinician');

        $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(ResendInvitation::class)($member)), 'not_invited');
        $this->assertRefused(fn () => $this->actAs($this->admin, fn () => app(RevokeInvitation::class)($member)), 'not_invited');

        // The active member is untouched.
        $row = $this->reload($member);
        $this->assertSame(MembershipStatus::Active, $row->status);
        $this->assertSame($member->user_id, $row->user_id);
        $this->assertSame(['clinician'], $this->roleKeysOf($row));
        $this->assertSame([], $this->sentInvitations());
    }

    #[Test]
    public function revoking_deletes_the_invitation_and_its_link_and_frees_the_email(): void
    {
        $invite = $this->invite('jane@example.com', ['clinician', 'staff']);
        $token = $this->lastInvitation()['token'];

        $this->actAs($this->admin, fn () => app(RevokeInvitation::class)($invite));

        $this->assertNull($this->reload($invite));
        $this->assertSame(0, DB::table('membership_roles')->where('membership_id', $invite->id)->count());
        $this->assertNull(app(FindInvitation::class)($token));

        $audit = $this->lastAudit('team.invitation_revoked', $this->org);
        $this->assertSame('jane@example.com', $audit->before['email']);
        $this->assertSame(['Clinician', 'Staff'], $audit->before['roles']);

        $this->invite('jane@example.com');   // the same address can be invited afresh
        $this->assertCount(2, $this->sentInvitations());
    }

    #[Test]
    public function a_manager_cannot_resend_or_revoke_an_invitation_to_an_administrator(): void
    {
        $manager = $this->addStaff($this->org, 'practice_manager');
        $adminInvite = $this->invite('future-boss@example.com', [RoleTemplates::ORG_ADMIN]);
        $clinicianInvite = $this->invite('future-clinician@example.com');
        $mails = count($this->sentInvitations());

        $this->assertRefused(fn () => $this->actAs($manager, fn () => app(ResendInvitation::class)($adminInvite)), 'privilege_escalation');
        $this->assertRefused(fn () => $this->actAs($manager, fn () => app(RevokeInvitation::class)($adminInvite)), 'privilege_escalation');
        $this->assertNotNull($this->reload($adminInvite));
        $this->assertCount($mails, $this->sentInvitations());

        // A clinician's invitation is within their reach.
        $this->travel(2)->minutes();   // past the resend cooldown
        $this->actAs($manager, fn () => app(ResendInvitation::class)($clinicianInvite));
        $this->actAs($manager, fn () => app(RevokeInvitation::class)($clinicianInvite));
        $this->assertNull($this->reload($clinicianInvite));
    }

    #[Test]
    public function another_organizations_invitation_is_not_found(): void
    {
        $other = $this->createOrganization();
        $foreign = $this->invite('theirs@example.com', as: $other->ownerMembership);
        $token = $this->lastInvitation()['token'];
        $hash = $this->reload($foreign)->invitation_token_hash;

        foreach ([ResendInvitation::class, RevokeInvitation::class] as $action) {
            try {
                $this->actAs($this->admin, fn () => app($action)($foreign));
                $this->fail("{$action} reached another organization's invitation.");
            } catch (ModelNotFoundException) {
                // expected
            }
        }

        $row = $this->reload($foreign);
        $this->assertNotNull($row);
        $this->assertSame($hash, $row->invitation_token_hash);
        $this->assertSame($token, $this->lastInvitation()['token']);   // no new mail either
    }

    #[Test]
    public function inviting_does_not_touch_users_or_other_organizations(): void
    {
        $users = User::query()->count();
        $other = $this->createOrganization();
        $beforeOther = OrganizationMembership::acrossTenants()->where('organization_id', $other->organization->id)->count();

        $this->invite('new.person@example.com');

        $this->assertSame($users + 1, User::query()->count());   // only $other's owner, created above
        $this->assertSame($beforeOther, OrganizationMembership::acrossTenants()->where('organization_id', $other->organization->id)->count());
    }
}
