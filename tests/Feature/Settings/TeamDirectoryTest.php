<?php

namespace Tests\Feature\Settings;

use App\Domain\Identity\InvitationTokens;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Identity\TeamDirectory;
use App\Domain\Platform\CreatedOrganization;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Service;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Settings\Concerns\WorksInsideOrganizations;
use Tests\TestCase;

class TeamDirectoryTest extends TestCase
{
    use WorksInsideOrganizations;

    private CreatedOrganization $created;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();
        $this->org = $this->created->organization;
    }

    private function staff(string $name, string $roleKey = 'clinician', MembershipStatus $status = MembershipStatus::Active, ?Organization $in = null): OrganizationMembership
    {
        $organization = $in ?? $this->org;
        $user = User::factory()->create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.com']);
        $member = $this->addStaff($organization, $roleKey, ['status' => $status], $user);

        return $member;
    }

    private function pending(string $email, string $roleKey = 'staff', ?Organization $in = null): OrganizationMembership
    {
        $organization = $in ?? $this->org;
        $role = $this->roleOf($organization, $roleKey);

        return $this->inTenant($organization, function () use ($email, $role) {
            $invite = new OrganizationMembership;
            $invite->forceFill([
                'status' => MembershipStatus::Invited, 'invited_email' => $email,
                'invitation_token_hash' => InvitationTokens::hash(InvitationTokens::generate()),
                'invitation_expires_at' => now()->addDays(7),
            ])->save();
            $invite->roles()->attach($role->id);

            return $invite;
        });
    }

    /** @return array{queries: int, page: LengthAwarePaginator} */
    private function list(?string $status = null, ?string $search = null, int $perPage = 25, ?Organization $in = null): array
    {
        $organization = $in ?? $this->org;

        return $this->inTenant($organization, function () use ($status, $search, $perPage) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $page = app(TeamDirectory::class)->paginate($status, $search, $perPage);

            // Rendering touches every member's user and roles: none of it may query again.
            foreach ($page as $member) {
                $member->roles->pluck('name');
                $member->user?->name;
                $member->displayName();
            }
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return ['queries' => $queries, 'page' => $page];
        });
    }

    #[Test]
    public function listing_the_team_costs_the_same_few_queries_however_many_people_are_on_it(): void
    {
        $this->staff('Ama Mensah');
        $small = $this->list();

        foreach (range(1, 20) as $i) {
            $this->staff(sprintf('Staff Person %02d', $i), $i % 3 === 0 ? 'receptionist' : 'clinician', $i % 5 === 0 ? MembershipStatus::Suspended : MembershipStatus::Active);
        }
        foreach (range(1, 4) as $i) {
            $this->pending("invitee{$i}@example.com");
        }
        $large = $this->list();

        $this->assertSame(26, $large['page']->total());   // owner + 21 staff + 4 invitations
        $this->assertSame($small['queries'], $large['queries']);
        $this->assertLessThanOrEqual(4, $large['queries']);   // count, page, users, roles
    }

    #[Test]
    public function the_list_carries_each_members_roles_and_user_without_further_queries(): void
    {
        $member = $this->staff('Ama Mensah', 'receptionist');
        $this->giveRole($member, $this->roleOf($this->org, 'billing'));
        $invite = $this->pending('new@example.com', 'supervisor');

        $page = $this->list()['page'];

        $ama = $page->firstWhere('id', $member->id);
        $this->assertSame(['Billing Staff', 'Receptionist'], $ama->roles->pluck('name')->all());   // sorted by name
        $this->assertSame('Ama Mensah', $ama->user->name);
        $pending = $page->firstWhere('id', $invite->id);
        $this->assertNull($pending->user);
        $this->assertSame('new@example.com', $pending->displayName());
        $this->assertSame(['Clinical Supervisor'], $pending->roles->pluck('name')->all());
        $owner = $page->firstWhere('id', $this->created->ownerMembership->id);
        $this->assertSame(['Organization Administrator'], $owner->roles->pluck('name')->all());
    }

    #[Test]
    public function members_are_ordered_by_status_then_name_and_can_be_filtered(): void
    {
        $this->staff('Zed Active');
        $this->staff('Bea Active');
        $this->staff('Cal Suspended', status: MembershipStatus::Suspended);
        $this->staff('Dee Deactivated', status: MembershipStatus::Deactivated);
        $this->pending('aaa.invited@example.com');

        $all = $this->list()['page']->map(fn ($m) => $m->displayName())->all();
        $owner = $this->created->ownerMembership->user()->first()->name;

        // Active (by name), then invited, then suspended, then deactivated.
        $active = array_slice($all, 0, 3);
        $this->assertEqualsCanonicalizing([$owner, 'Bea Active', 'Zed Active'], $active);
        $this->assertSame(['Bea Active', 'Zed Active'], array_values(array_filter($active, fn ($n) => str_ends_with($n, 'Active'))));
        $this->assertSame(['aaa.invited@example.com', 'Cal Suspended', 'Dee Deactivated'], array_slice($all, 3));

        $this->assertSame(['Cal Suspended'], $this->list('suspended')['page']->map(fn ($m) => $m->displayName())->all());
        $this->assertSame(['aaa.invited@example.com'], $this->list('invited')['page']->map(fn ($m) => $m->displayName())->all());
        $this->assertSame(3, $this->list('active')['page']->total());
        $this->assertSame(6, $this->list('not-a-status')['page']->total());   // an unknown status filters nothing

        $counts = $this->inTenant($this->org, fn () => app(TeamDirectory::class)->statusCounts());
        $this->assertSame(['invited' => 1, 'active' => 3, 'suspended' => 1, 'deactivated' => 1], $counts);
    }

    #[Test]
    public function search_matches_names_and_emails_ignoring_case_and_treats_wildcards_literally(): void
    {
        $this->staff('Ama Mensah');
        $this->staff('Kofi Boateng');
        $this->staff('Percent 100% Sure');
        $this->pending('ama.invited@example.com');
        $names = fn (?string $q) => $this->list(search: $q)['page']->map(fn ($m) => $m->displayName())->all();

        $this->assertEqualsCanonicalizing(['Ama Mensah', 'ama.invited@example.com'], $names('AMA'));
        $this->assertSame(['Kofi Boateng'], $names('boateng'));
        $this->assertSame(['Kofi Boateng'], $names('kofi.boateng@'));   // by email address
        $this->assertSame(['Percent 100% Sure'], $names('100%'));          // % is not a wildcard
        $this->assertSame([], $names('_'));                                 // nor is _
        $this->assertSame([], $names('no such person'));
        $this->assertCount(5, $names('   '));                               // blank: no filter
        $this->assertSame([], $names("' OR 1=1 --"));
    }

    #[Test]
    public function the_list_is_paginated(): void
    {
        foreach (range(1, 30) as $i) {
            $this->staff(sprintf('Member %02d', $i));
        }

        $first = $this->list(perPage: 10)['page'];

        $this->assertCount(10, $first);
        $this->assertSame(31, $first->total());
        $this->assertSame(4, $first->lastPage());
    }

    #[Test]
    public function only_this_organizations_people_are_listed_and_counted(): void
    {
        $other = $this->createOrganization();
        $this->staff('Ours Person');
        $this->staff('Theirs Person', in: $other->organization);
        $this->pending('theirs.invited@example.com', in: $other->organization);

        $names = $this->list()['page']->map(fn ($m) => $m->displayName())->all();

        $this->assertContains('Ours Person', $names);
        $this->assertNotContains('Theirs Person', $names);
        $this->assertNotContains('theirs.invited@example.com', $names);
        $this->assertSame(2, $this->list()['page']->total());

        $theirs = $this->list(in: $other->organization)['page']->map(fn ($m) => $m->displayName())->all();
        $this->assertEqualsCanonicalizing([$other->ownerMembership->user()->first()->name, 'Theirs Person', 'theirs.invited@example.com'], $theirs);
    }

    #[Test]
    public function a_providers_services_are_listed_by_name(): void
    {
        $provider = $this->staff('Ama Mensah');
        $this->inTenant($this->org, function () use ($provider) {
            Service::factory()->create(['name' => 'Zeta session'])->providers()->attach($provider->id);
            Service::factory()->create(['name' => 'Alpha session'])->providers()->attach($provider->id);
            Service::factory()->create(['name' => 'Unrelated']);
        });

        $names = $this->inTenant($this->org, fn () => app(TeamDirectory::class)->serviceNames($provider));

        $this->assertSame(['Alpha session', 'Zeta session'], $names);
        $this->assertSame([], $this->inTenant($this->org, fn () => app(TeamDirectory::class)->serviceNames($this->created->ownerMembership)));
    }
}
