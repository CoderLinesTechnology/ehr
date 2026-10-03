<?php

namespace Tests\Feature\Invitations;

use App\Domain\Identity\AcceptedInvitation;
use App\Domain\Identity\AcceptInvitation;
use App\Domain\Identity\FindInvitation;
use App\Domain\Identity\InvitationTokens;
use App\Domain\Identity\InvitationUnavailable;
use App\Domain\Identity\InviteStaff;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Identity\ResendInvitation;
use App\Domain\Identity\RevokeInvitation;
use App\Domain\Identity\StaffSeats;
use App\Domain\Platform\CreateOrganization;
use App\Domain\Platform\OrganizationStatus;
use App\Domain\Platform\CreatedOrganization;
use App\Domain\Shared\DomainException;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Settings\Concerns\WorksInsideOrganizations;
use Tests\TestCase;

/**
 * The invitation journey end to end at the domain level: invited by an administrator, found by the
 * emailed token, accepted by the signed-in user whose email matches. Acceptance runs with NO tenant
 * context, exactly as the acceptance route does (the token, not the URL, identifies the organization).
 */
class InvitationLifecycleTest extends TestCase
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

    /** Invite through the real action; returns [membership, plain token from the email]. */
    private function invite(string $email, array $roleKeys = ['clinician'], ?CreatedOrganization $in = null): array
    {
        $created = $in ?? $this->created;
        $roleIds = array_map(fn (string $key) => $this->roleOf($created->organization, $key)->id, $roleKeys);

        $invite = $this->actAs($created->ownerMembership, fn () => app(InviteStaff::class)($email, $roleIds, title: 'Counsellor'));

        return [$invite, $this->lastInvitation()['token']];
    }

    /** A pending invitation written directly (for situations InviteStaff itself refuses to create). */
    private function craftInvite(Organization $organization, string $email, array $roleKeys): string
    {
        $token = InvitationTokens::generate();
        $roleIds = array_map(fn (string $key) => $this->roleOf($organization, $key)->id, $roleKeys);

        $this->inTenant($organization, function () use ($email, $token, $roleIds) {
            $invite = new OrganizationMembership;
            $invite->forceFill([
                'status' => MembershipStatus::Invited, 'invited_email' => $email,
                'invitation_token_hash' => InvitationTokens::hash($token),
                'invitation_expires_at' => now()->addDays(7), 'invited_by_user_id' => $this->admin->user_id,
            ])->save();
            $invite->roles()->attach($roleIds);
        });

        return $token;
    }

    /** Accept as $user, outside any organization. */
    private function accept(string $token, User $user): AcceptedInvitation
    {
        $this->actingAs($user);

        return app(AcceptInvitation::class)($token, $user);
    }

    private function newUser(string $email, bool $verified = true): User
    {
        return ($verified ? User::factory() : User::factory()->unverified())->create(['email' => $email]);
    }

    private function memberships(User $user): int
    {
        return OrganizationMembership::acrossTenants()->where('user_id', $user->id)->count();
    }

    #[Test]
    public function an_invited_person_accepts_with_a_new_account_and_becomes_an_active_member(): void
    {
        [$invite, $token] = $this->invite('jane@example.com', ['clinician', 'staff']);

        // The link resolves to the organization, the inviter and the roles on offer — for anyone holding the token.
        $found = app(FindInvitation::class)($token);
        $this->assertSame($invite->id, $found->id);
        $details = app(FindInvitation::class)->details($found);
        $this->assertSame($this->org->name, $details['organization']);
        $this->assertSame($this->admin->user()->first()->name, $details['inviter']);
        $this->assertSame(['Clinician', 'Staff'], $details['roles']);
        $this->assertSame('jane@example.com', $details['email']);
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $details['expires_at']->getTimestamp(), 5);

        // A freshly registered invitee has not verified their email yet; the link still works for them.
        $user = $this->newUser('jane@example.com', verified: false);
        $result = $this->accept($token, $user);

        $this->assertSame(AcceptedInvitation::JOINED, $result->outcome);
        $this->assertSame($this->org->id, $result->organization->id);
        $this->assertSame($invite->id, $result->membership->id);

        $row = $this->reload($invite);
        $this->assertSame(MembershipStatus::Active, $row->status);
        $this->assertSame($user->id, $row->user_id);
        $this->assertNotNull($row->joined_at);
        $this->assertNull($row->invitation_token_hash);   // the link is dead from now on
        $this->assertNull($row->invitation_expires_at);
        $this->assertSame('jane@example.com', $row->invited_email);
        $this->assertSame('Counsellor', $row->title);
        $this->assertEqualsCanonicalizing(['clinician', 'staff'], $this->roleKeysOf($row));
        $this->assertSame(1, $this->memberships($user));
        $this->assertNull(app(FindInvitation::class)($token));

        // The roles take effect straight away (the resolver was flushed).
        $this->assertTrue($this->inTenant($this->org, fn () => Gate::forUser($user)->allows('clients.create'), $row));
        $this->assertFalse($this->inTenant($this->org, fn () => Gate::forUser($user)->allows('roles.manage'), $row));
    }

    #[Test]
    public function acceptance_is_audited_for_the_organization_with_the_new_member_as_actor(): void
    {
        [$invite, $token] = $this->invite('jane@example.com');
        $user = $this->newUser('jane@example.com');

        $this->accept($token, $user);

        $audit = $this->lastAudit('team.invitation_accepted', $this->org);
        $this->assertNotNull($audit);
        $this->assertSame($user->id, $audit->actor_user_id);
        $this->assertSame('organization', $audit->context);
        $this->assertSame($this->org->id, $audit->organization_id);
        $this->assertSame($invite->id, $audit->subject_id);
        $this->assertSame(AcceptedInvitation::JOINED, $audit->metadata['outcome']);
        $this->assertStringContainsString($user->name, $audit->summary);
        $this->assertStringNotContainsString($token, json_encode($audit->toArray()));

        // Visible in the organization's own audit log, never in the platform's.
        $this->assertTrue(AuditLog::query()->forOrganization($this->org->id)->where('action', 'team.invitation_accepted')->exists());
        $this->assertFalse(AuditLog::query()->platformVisible()->where('action', 'team.invitation_accepted')->exists());
    }

    #[Test]
    public function the_email_comparison_ignores_case(): void
    {
        [$invite, $token] = $this->invite('jane@example.com');
        DB::table('organization_memberships')->where('id', $invite->id)->update(['invited_email' => 'Jane@Example.COM']);

        $this->accept($token, $this->newUser('jane@example.com'));

        $this->assertSame(MembershipStatus::Active, $this->reload($invite)->status);
    }

    #[Test]
    public function someone_signed_in_with_another_email_cannot_accept_and_the_invitation_survives(): void
    {
        [$invite, $token] = $this->invite('jane@example.com');
        $hash = $this->reload($invite)->invitation_token_hash;
        $stranger = $this->newUser('mallory@example.com');

        $this->assertRefused(fn () => $this->accept($token, $stranger), 'invitation_wrong_email');

        $row = $this->reload($invite);
        $this->assertSame(MembershipStatus::Invited, $row->status);
        $this->assertNull($row->user_id);
        $this->assertSame($hash, $row->invitation_token_hash);
        $this->assertSame(0, $this->memberships($stranger));
        $this->assertSame(0, $this->auditCount('team.invitation_accepted', $this->org));

        // The person it was meant for can still accept it afterwards.
        $this->accept($token, $this->newUser('jane@example.com'));
        $this->assertSame(MembershipStatus::Active, $this->reload($invite)->status);
    }

    public static function deadLinks(): array
    {
        return [
            'unknown' => ['unknown'],
            'expired' => ['expired'],
            'revoked' => ['revoked'],
            'already used' => ['used'],
            'empty' => ['empty'],
            'oversized' => ['oversized'],
            'injection attempt' => ['injection'],
        ];
    }

    private function deadToken(string $kind, User $user): string
    {
        switch ($kind) {
            case 'unknown':
                return InvitationTokens::generate();
            case 'expired':
                [, $token] = $this->invite('expired@example.com');
                $this->travel(8)->days();

                return $token;
            case 'revoked':
                [$invite, $token] = $this->invite('revoked@example.com');
                $this->actAs($this->admin, fn () => app(RevokeInvitation::class)($invite));

                return $token;
            case 'used':
                [, $token] = $this->invite($user->email);
                $this->accept($token, $user);

                return $token;
            case 'empty':
                return '';
            case 'oversized':
                return str_repeat('a', 5000);
            default:
                return "' OR 1=1 --";
        }
    }

    #[Test]
    #[DataProvider('deadLinks')]
    public function unknown_expired_revoked_and_used_links_are_the_same_neutral_refusal(string $kind): void
    {
        $user = $this->newUser('jane@example.com');
        $token = $this->deadToken($kind, $user);

        $this->assertNull(app(FindInvitation::class)($token));

        try {
            $this->accept($token, $user);
            $this->fail('A dead link was accepted.');
        } catch (InvitationUnavailable $e) {
            // Identical words and code whatever the reason, so a link cannot be probed.
            $this->assertSame(InvitationUnavailable::make()->userMessage(), $e->userMessage());
            $this->assertSame('invitation_unavailable', $e->errorCode());
            $this->assertNull($e->field());
        }
    }

    #[Test]
    public function the_refusals_reveal_nothing_about_which_invitations_exist(): void
    {
        [, $live] = $this->invite('someone@example.com');
        $other = $this->newUser('jane@example.com');

        // A live token held by the wrong person says "wrong email", never anything about the organization…
        try {
            $this->accept($live, $other);
            $this->fail('Accepted by the wrong person.');
        } catch (DomainException $e) {
            $this->assertStringNotContainsString($this->org->name, $e->userMessage());
            $this->assertStringNotContainsString('someone@example.com', $e->userMessage());
        }

        // …and a made-up token says exactly what an expired one says.
        $this->expectException(InvitationUnavailable::class);
        $this->accept(InvitationTokens::generate(), $other);
    }

    #[Test]
    public function accepting_twice_is_safe_the_second_attempt_changes_nothing(): void
    {
        [$invite, $token] = $this->invite('jane@example.com');
        $user = $this->newUser('jane@example.com');

        $this->accept($token, $user);

        $this->expectException(InvitationUnavailable::class);

        try {
            $this->accept($token, $user);   // a double click, a replayed request
        } finally {
            $this->assertSame(1, $this->memberships($user));
            $this->assertSame(MembershipStatus::Active, $this->reload($invite)->status);
            $this->assertSame(1, $this->auditCount('team.invitation_accepted', $this->org));
        }
    }

    #[Test]
    public function an_expired_invitation_works_again_after_it_is_resent(): void
    {
        [$invite, $old] = $this->invite('jane@example.com');
        $this->travel(8)->days();
        $user = $this->newUser('jane@example.com');

        $this->assertRefused(fn () => $this->accept($old, $user), 'invitation_unavailable');

        $this->actAs($this->admin, fn () => app(ResendInvitation::class)($invite));
        $new = $this->lastInvitation()['token'];

        $this->assertRefused(fn () => $this->accept($old, $user), 'invitation_unavailable');   // the old link stays dead
        $this->accept($new, $user);
        $this->assertSame(MembershipStatus::Active, $this->reload($invite)->status);
    }

    #[Test]
    public function an_invitation_already_holds_its_seat_so_accepting_never_needs_another(): void
    {
        $starter = $this->createOrganization(plan: 'starter');   // 3 seats: the owner and two invitations
        [$a, $tokenA] = $this->invite('a@example.com', in: $starter);
        [$b, $tokenB] = $this->invite('b@example.com', in: $starter);
        $seats = fn () => $this->actAs($starter->ownerMembership, fn () => app(StaffSeats::class)->inUse());
        $this->assertSame(3, $seats());

        $this->accept($tokenA, $this->newUser('a@example.com'));
        $this->accept($tokenB, $this->newUser('b@example.com'));

        $this->assertSame(3, $seats());
        $this->assertSame(MembershipStatus::Active, $this->reload($a)->status);
        $this->assertSame(MembershipStatus::Active, $this->reload($b)->status);
    }

    #[Test]
    public function someone_who_already_belongs_is_not_given_a_second_membership(): void
    {
        $member = $this->addStaff($this->org, 'clinician');
        $token = $this->craftInvite($this->org, $member->user->email, ['staff']);   // InviteStaff would refuse this; it can still exist

        $result = $this->accept($token, $member->user);

        $this->assertSame(AcceptedInvitation::ALREADY_MEMBER, $result->outcome);
        $this->assertSame($member->id, $result->membership->id);
        $this->assertSame(1, $this->memberships($member->user));
        $this->assertSame(['clinician'], $this->roleKeysOf($member));   // nothing is granted by the redundant invitation
        $this->assertSame(0, OrganizationMembership::acrossTenants()->where('organization_id', $this->org->id)->where('status', 'invited')->count());   // the invitation row is retired
        $this->assertSame(AcceptedInvitation::ALREADY_MEMBER, $this->lastAudit('team.invitation_accepted', $this->org)->metadata['outcome']);
        $this->assertSame($member->id, $this->lastAudit('team.invitation_accepted', $this->org)->subject_id);
    }

    #[Test]
    public function a_deactivated_member_comes_back_with_the_invited_roles_without_using_a_second_seat(): void
    {
        $member = $this->addStaff($this->org, 'clinician');
        $this->inTenant($this->org, fn () => $this->reload($member)->forceFill(['status' => MembershipStatus::Deactivated, 'deactivated_at' => now()])->save());
        $token = $this->craftInvite($this->org, $member->user->email, ['staff']);
        $seatsBefore = $this->actAs($this->admin, fn () => app(StaffSeats::class)->inUse());   // owner + the pending invitation

        $result = $this->accept($token, $member->user);

        $this->assertSame(AcceptedInvitation::REJOINED, $result->outcome);
        $row = $this->reload($member);
        $this->assertSame(MembershipStatus::Active, $row->status);
        $this->assertNull($row->deactivated_at);
        $this->assertSame(['staff'], $this->roleKeysOf($row));   // the roles of this invitation replace the old ones
        $this->assertSame(1, $this->memberships($member->user));
        $this->assertSame($seatsBefore, $this->actAs($this->admin, fn () => app(StaffSeats::class)->inUse()));   // the invitation's seat became theirs
        $this->assertTrue($this->inTenant($this->org, fn () => Gate::forUser($member->user)->allows('appointments.view'), $row));
        $this->assertFalse($this->inTenant($this->org, fn () => Gate::forUser($member->user)->allows('clients.create'), $row));
    }

    #[Test]
    public function a_suspended_member_cannot_lift_their_suspension_with_an_invitation(): void
    {
        $member = $this->addStaff($this->org, 'clinician');
        $this->inTenant($this->org, fn () => $this->reload($member)->forceFill(['status' => MembershipStatus::Suspended])->save());
        $token = $this->craftInvite($this->org, $member->user->email, ['org_admin']);

        $this->assertRefused(fn () => $this->accept($token, $member->user), 'membership_suspended');

        $row = $this->reload($member);
        $this->assertSame(MembershipStatus::Suspended, $row->status);
        $this->assertSame(['clinician'], $this->roleKeysOf($row));
        $this->assertNotNull(app(FindInvitation::class)($token));   // still pending for an administrator to deal with
    }

    #[Test]
    public function a_token_only_ever_affects_the_organization_it_was_issued_by(): void
    {
        $other = $this->createOrganization();
        [$inviteHere, $tokenHere] = $this->invite('jane@example.com');
        [$inviteThere, $tokenThere] = $this->invite('jane@example.com', in: $other);
        $jane = $this->newUser('jane@example.com');
        $before = OrganizationMembership::acrossTenants()->where('organization_id', $other->organization->id)->count();

        $result = $this->accept($tokenHere, $jane);

        $this->assertSame($this->org->id, $result->organization->id);
        $this->assertSame(1, $this->memberships($jane));
        $this->assertSame(MembershipStatus::Invited, $this->reload($inviteThere)->status);   // the other organization's invitation is untouched
        $this->assertNotNull(app(FindInvitation::class)($tokenThere));
        $this->assertSame($before, OrganizationMembership::acrossTenants()->where('organization_id', $other->organization->id)->count());

        // Accepting the second one too makes her a member of both, each membership separate.
        $this->accept($tokenThere, $jane);
        $this->assertSame(2, $this->memberships($jane));
        $this->assertEqualsCanonicalizing(
            [$this->org->id, $other->organization->id],
            OrganizationMembership::acrossTenants()->where('user_id', $jane->id)->pluck('organization_id')->all(),
        );
    }

    #[Test]
    public function an_existing_member_of_another_organization_can_accept_an_invitation_here(): void
    {
        $elsewhere = $this->createOrganization();
        $member = $this->addStaff($elsewhere->organization, 'clinician');
        [, $token] = $this->invite($member->user->email);

        $this->accept($token, $member->user);

        $this->assertSame(2, $this->memberships($member->user));
        $this->assertSame(MembershipStatus::Active, $this->reload($member)->status);   // the other membership is unaffected
    }

    #[Test]
    public function an_owner_invited_when_the_platform_team_creates_an_organization_accepts_and_becomes_its_administrator(): void
    {
        $platformAdmin = $this->platformUser();
        $created = app(CreateOrganization::class)(
            profile: ['name' => 'Kumasi Mind Clinic', 'country_code' => 'GH', 'timezone' => 'Africa/Accra', 'currency' => 'GHS'],
            plan: Plan::query()->where('key', 'starter')->firstOrFail(),
            status: OrganizationStatus::Active,
            ownerEmail: 'owner@kumasi.example',
            actor: $platformAdmin,
        );
        $token = $created->invitationToken;
        $this->assertNotNull($token);

        $found = app(FindInvitation::class)($token);
        $this->assertSame($created->ownerMembership->id, $found->id);
        $details = app(FindInvitation::class)->details($found);
        $this->assertSame('Kumasi Mind Clinic', $details['organization']);
        $this->assertSame($platformAdmin->name, $details['inviter']);
        $this->assertSame(['Organization Administrator'], $details['roles']);

        // The wrong person is refused; the owner, with the invited address, becomes the first administrator.
        $this->assertRefused(fn () => $this->accept($token, $this->newUser('someone.else@example.com')), 'invitation_wrong_email');
        $owner = $this->newUser('owner@kumasi.example');
        $result = $this->accept($token, $owner);

        $this->assertSame(AcceptedInvitation::JOINED, $result->outcome);
        $membership = $this->reload($created->ownerMembership);
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertSame($owner->id, $membership->user_id);
        $this->assertSame(['org_admin'], $this->roleKeysOf($membership));
        $this->assertTrue($this->inTenant($created->organization, fn () => Gate::forUser($owner)->allows('roles.manage'), $membership));
        $this->assertNull(app(FindInvitation::class)($token));
    }

    #[Test]
    public function the_token_is_long_random_and_only_its_hash_is_kept(): void
    {
        $tokens = [InvitationTokens::generate(), InvitationTokens::generate(), InvitationTokens::generate()];

        foreach ($tokens as $token) {
            $this->assertSame(64, strlen($token));
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $token);
            $this->assertSame(hash('sha256', $token), InvitationTokens::hash($token));
            $this->assertSame(64, strlen(InvitationTokens::hash($token)));
        }
        $this->assertCount(3, array_unique($tokens));

        [$invite, $token] = $this->invite('jane@example.com');
        $this->assertNotSame($token, $this->reload($invite)->invitation_token_hash);
        $this->assertSame(InvitationTokens::hash($token), $this->reload($invite)->invitation_token_hash);
    }
}
