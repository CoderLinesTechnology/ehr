<?php

namespace Tests\Feature\Settings;

use App\Domain\Identity\InvitationTokens;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Platform\CreatedOrganization;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Settings\Concerns\WorksInsideOrganizations;
use Tests\TestCase;

/**
 * "May this person do this to this member?" as screens and routes will ask it. The actions enforce the
 * same rules themselves (see TeamMembersTest); the policy exists so a screen never offers what would be refused.
 */
class MembershipPolicyTest extends TestCase
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

    private function ask(OrganizationMembership $actor, string $ability, OrganizationMembership|string|null $subject = null): Response
    {
        return $this->actAs($actor, fn () => Gate::forUser($actor->user)->inspect($ability, $subject ?? OrganizationMembership::class));
    }

    private function pendingInvite(string $roleKey = 'clinician'): OrganizationMembership
    {
        $role = $this->roleOf($this->org, $roleKey);

        return $this->inTenant($this->org, function () use ($role) {
            $invite = new OrganizationMembership;
            $invite->forceFill([
                'status' => MembershipStatus::Invited, 'invited_email' => 'pending@example.com',
                'invitation_token_hash' => InvitationTokens::hash(InvitationTokens::generate()), 'invitation_expires_at' => now()->addDays(7),
            ])->save();
            $invite->roles()->attach($role->id);

            return $invite;
        });
    }

    #[Test]
    public function an_administrator_may_do_everything_to_everyone_except_to_themselves(): void
    {
        $clinician = $this->addStaff($this->org, 'clinician');
        $invite = $this->pendingInvite();

        foreach (['viewAny', 'invite'] as $ability) {
            $this->assertTrue($this->ask($this->admin, $ability)->allowed(), $ability);
        }
        foreach (['view', 'update', 'changeAccess', 'changeStatus'] as $ability) {
            $this->assertTrue($this->ask($this->admin, $ability, $clinician)->allowed(), $ability);
        }
        $this->assertTrue($this->ask($this->admin, 'resendInvitation', $invite)->allowed());
        $this->assertTrue($this->ask($this->admin, 'revokeInvitation', $invite)->allowed());

        // Themselves: they can edit their own details but never suspend or deactivate themselves.
        $this->assertTrue($this->ask($this->admin, 'update', $this->admin)->allowed());
        $this->assertTrue($this->ask($this->admin, 'view', $this->admin)->allowed());
        $self = $this->ask($this->admin, 'changeStatus', $this->admin);
        $this->assertTrue($self->denied());
        $this->assertStringContainsString('your own', $self->message());
    }

    #[Test]
    public function a_pending_invitation_is_resent_or_revoked_not_suspended(): void
    {
        $clinician = $this->addStaff($this->org, 'clinician');
        $invite = $this->pendingInvite();

        $this->assertTrue($this->ask($this->admin, 'changeStatus', $invite)->denied());
        $this->assertStringContainsString('Revoke the invitation', $this->ask($this->admin, 'changeStatus', $invite)->message());

        // And the invitation actions only apply to a pending invitation.
        $this->assertTrue($this->ask($this->admin, 'resendInvitation', $clinician)->denied());
        $this->assertTrue($this->ask($this->admin, 'revokeInvitation', $clinician)->denied());
    }

    #[Test]
    public function a_manager_manages_those_below_them_but_not_the_administrator(): void
    {
        $manager = $this->addStaff($this->org, 'practice_manager');
        $clinician = $this->addStaff($this->org, 'clinician');
        $adminInvite = $this->pendingInvite('org_admin');
        $strong = $this->addStaff($this->org, 'clinician');
        $this->giveRole($strong, $this->makeRole($this->org, 'Role Editor', ['roles.manage']));

        $this->assertTrue($this->ask($manager, 'viewAny')->allowed());
        $this->assertTrue($this->ask($manager, 'invite')->allowed());
        $this->assertTrue($this->ask($manager, 'view', $this->admin)->allowed());          // anyone can be seen…
        $this->assertTrue($this->ask($manager, 'update', $this->admin)->allowed());        // …their details edited…

        $this->assertTrue($this->ask($manager, 'changeAccess', $clinician)->allowed());
        $this->assertTrue($this->ask($manager, 'changeStatus', $clinician)->allowed());

        foreach ([$this->admin, $strong] as $outranking) {                                  // …but not their access
            $denied = $this->ask($manager, 'changeAccess', $outranking);
            $this->assertTrue($denied->denied());
            $this->assertStringContainsString('only an administrator', $denied->message());
            $this->assertTrue($this->ask($manager, 'changeStatus', $outranking)->denied());
        }
        $this->assertTrue($this->ask($manager, 'resendInvitation', $adminInvite)->denied());
        $this->assertTrue($this->ask($manager, 'revokeInvitation', $adminInvite)->denied());
    }

    #[Test]
    public function a_clinician_can_see_the_team_but_change_nothing(): void
    {
        $clinician = $this->addStaff($this->org, 'clinician');   // team.view only
        $other = $this->addStaff($this->org, 'staff');

        $this->assertTrue($this->ask($clinician, 'viewAny')->allowed());
        $this->assertTrue($this->ask($clinician, 'view', $other)->allowed());

        foreach (['invite'] as $ability) {
            $this->assertTrue($this->ask($clinician, $ability)->denied(), $ability);
        }
        foreach (['update', 'changeAccess', 'changeStatus'] as $ability) {
            $this->assertTrue($this->ask($clinician, $ability, $other)->denied(), $ability);
        }
    }

    #[Test]
    public function the_policy_follows_the_permissions_as_they_change(): void
    {
        $manager = $this->addStaff($this->org, 'practice_manager');
        $target = $this->addStaff($this->org, 'staff');

        $this->assertTrue($this->ask($manager, 'changeStatus', $target)->allowed());

        $this->revokeFromRole($this->org, 'practice_manager', 'team.manage');
        $this->assertTrue($this->ask($manager, 'changeStatus', $target)->denied());
        $this->assertTrue($this->ask($manager, 'invite')->denied());
        $this->assertTrue($this->ask($manager, 'viewAny')->allowed());   // team.view is a separate permission

        $this->revokeFromRole($this->org, 'practice_manager', 'team.view');
        $this->assertTrue($this->ask($manager, 'viewAny')->denied());
    }

    #[Test]
    public function another_organizations_members_do_not_exist_for_the_policy(): void
    {
        $other = $this->createOrganization();
        $foreign = $this->addStaff($other->organization, 'clinician');
        $foreignInvite = $this->inTenant($other->organization, function () {
            $invite = new OrganizationMembership;
            $invite->forceFill([
                'status' => MembershipStatus::Invited, 'invited_email' => 'theirs@example.com',
                'invitation_token_hash' => InvitationTokens::hash(InvitationTokens::generate()), 'invitation_expires_at' => now()->addDays(7),
            ])->save();

            return $invite;
        });

        foreach (['view', 'update', 'changeAccess', 'changeStatus'] as $ability) {
            $response = $this->ask($this->admin, $ability, $foreign);
            $this->assertTrue($response->denied(), $ability);
            $this->assertSame(404, $response->status(), $ability);
        }
        foreach (['resendInvitation', 'revokeInvitation'] as $ability) {
            $this->assertSame(404, $this->ask($this->admin, $ability, $foreignInvite)->status(), $ability);
        }
    }

    #[Test]
    public function without_an_acting_membership_nothing_is_offered(): void
    {
        $clinician = $this->addStaff($this->org, 'clinician');
        $this->actingAs($this->admin->user()->first());

        // Signed in, but no organization context (or someone else's membership acting): a 404, not a hint.
        $response = Gate::forUser($this->admin->user()->first())->inspect('viewAny', OrganizationMembership::class);
        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());

        $someoneElses = $this->inTenant($this->org, fn () => Gate::forUser($this->admin->user()->first())->inspect('invite', OrganizationMembership::class), $clinician);
        $this->assertTrue($someoneElses->denied());
        $this->assertSame(404, $someoneElses->status());
    }
}
