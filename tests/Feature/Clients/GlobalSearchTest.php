<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\GlobalSearch;
use App\Domain\Clients\GlobalSearchResults;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Tenancy\TenantMismatch;
use App\Models\Client;
use App\Models\OrganizationEntitlement;
use App\Models\OrganizationMembership;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class GlobalSearchTest extends ClientsTestCase
{
    private $created;

    private OrganizationMembership $manager;

    private OrganizationMembership $dr1;

    private OrganizationMembership $clerk;

    private OrganizationMembership $kwame;

    private OrganizationMembership $yaw;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();
        $org = $this->created->organization;

        $this->manager = $this->addStaff($org, 'practice_manager', user: $this->person('Maame Manager', 'maame@carebase.test'));
        $this->kwame = $this->dr1 = $this->addStaff($org, 'clinician', user: $this->person('Kwame Mensah', 'kwame@carebase.test'));
        $this->yaw = $this->addStaff($org, 'receptionist', user: $this->person('Yaw Boateng', 'yaw.boateng@carebase.test'));
        $this->clerk = $this->addStaff($org, 'staff', user: $this->person('Cora Clerk', 'cora@carebase.test'));   // no client permissions, can see the team

        $this->client('Yaw', 'Boateng', 'yaw.client@example.org', ['primary_clinician_membership_id' => $this->dr1->id]);
        $this->client('Ama', 'Owusu', 'ama@example.org', ['primary_clinician_membership_id' => $this->dr1->id]);
        $this->client('Kofi', 'Mensah', 'kofi@example.org');
    }

    private function person(string $name, string $email): User
    {
        return User::factory()->create(['name' => $name, 'email' => $email]);
    }

    private function client(string $first, string $last, string $email, array $extra = []): Client
    {
        return $this->clientIn($this->created->organization, ['first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => null] + $extra);
    }

    private function search(OrganizationMembership $member, ?string $term): GlobalSearchResults
    {
        return $this->actAs($member, $this->created->organization, fn () => app(GlobalSearch::class)($member, $term));
    }

    /** @return list<string> */
    private function clientNames(GlobalSearchResults $results): array
    {
        return $results->clients->items->map(fn (Client $c) => $c->fullName())->all();
    }

    /** @return list<string> */
    private function staffNames(GlobalSearchResults $results): array
    {
        return $results->staff->items->map(fn (OrganizationMembership $m) => $m->user->name)->all();
    }

    #[Test]
    public function it_searches_clients_and_staff_together(): void
    {
        $results = $this->search($this->manager, 'yaw');

        $this->assertSame('yaw', $results->term);
        $this->assertSame(['Yaw Boateng'], $this->clientNames($results));
        $this->assertSame(['Yaw Boateng'], $this->staffNames($results));
        $this->assertTrue($results->canSearchClients);
        $this->assertTrue($results->canSearchStaff);
        $this->assertFalse($results->isEmpty());
    }

    #[Test]
    public function staff_are_found_by_name_in_any_order_and_by_email(): void
    {
        $this->assertSame(['Kwame Mensah'], $this->staffNames($this->search($this->manager, 'kwame mensah')));
        $this->assertSame(['Kwame Mensah'], $this->staffNames($this->search($this->manager, 'mensah kwame')));
        $this->assertSame(['Kwame Mensah'], $this->staffNames($this->search($this->manager, 'kwame@carebase')));
        $this->assertSame([], $this->staffNames($this->search($this->manager, 'kwame boateng')), 'every word must match');
        $this->assertContains('Cora Clerk', $this->staffNames($this->search($this->manager, '@carebase.test')));
    }

    #[Test]
    public function staff_members_loaded_for_display_carry_their_user(): void
    {
        $membership = $this->search($this->manager, 'kwame')->staff->items->first();

        $this->assertTrue($membership->relationLoaded('user'));
        $this->assertSame('kwame@carebase.test', $membership->user->email);
    }

    #[Test]
    public function only_active_staff_are_found(): void
    {
        $org = $this->created->organization;

        // deactivated, suspended, invited (no user yet) and a disabled account
        $this->inTenant($org, fn () => OrganizationMembership::query()->whereKey($this->yaw->id)->first()->forceFill(['status' => 'deactivated'])->save());
        $this->assertSame([], $this->staffNames($this->search($this->manager, 'boateng')) );

        $disabled = $this->addStaff($org, 'clinician', user: User::factory()->create(['name' => 'Dina Disabled', 'email' => 'dina@carebase.test', 'status' => 'disabled']));
        $this->assertSame([], $this->staffNames($this->search($this->manager, 'dina')));
        $this->assertNotNull($disabled);
    }

    #[Test]
    public function a_clinician_finds_only_their_clients_but_may_still_find_colleagues(): void
    {
        $results = $this->search($this->dr1, 'ama');
        $this->assertSame(['Ama Owusu'], $this->clientNames($results));

        $this->assertSame([], $this->clientNames($this->search($this->dr1, 'kofi')), 'Kofi is not dr1\'s client');
        $this->assertSame(['Kofi Mensah'], $this->clientNames($this->search($this->manager, 'kofi')));
        $this->assertSame(['Maame Manager'], $this->staffNames($this->search($this->dr1, 'maame')), 'team.view is in the clinician role');
    }

    #[Test]
    public function without_team_view_the_staff_are_not_searched_at_all(): void
    {
        $this->revokeFromRole($this->created->organization, 'clinician', 'team.view');

        [$results, $statements] = $this->actAs($this->dr1, $this->created->organization, fn () => $this->recordingQueries(
            fn () => app(GlobalSearch::class)($this->dr1, 'yaw'),
        ));

        $this->assertFalse($results->canSearchStaff);
        $this->assertTrue($results->staff->isEmpty());
        $this->assertSame(['Yaw Boateng'], $this->clientNames($results), 'clients are unaffected');
        foreach ($statements as $sql) {
            $this->assertStringNotContainsString('"users"', $sql, 'the user table is not touched without team.view: '.$sql);
        }
    }

    #[Test]
    public function without_a_client_permission_the_clients_are_not_searched_at_all(): void
    {
        [$results, $statements] = $this->actAs($this->clerk, $this->created->organization, fn () => $this->recordingQueries(
            fn () => app(GlobalSearch::class)($this->clerk, 'yaw'),
        ));

        $this->assertFalse($results->canSearchClients);
        $this->assertTrue($results->canSearchStaff);
        $this->assertTrue($results->clients->isEmpty());
        $this->assertSame(['Yaw Boateng'], $this->staffNames($results));
        foreach ($statements as $sql) {
            $this->assertStringNotContainsString('from "clients"', $sql, 'no client query without a client permission');
        }
    }

    #[Test]
    public function a_plan_without_the_clients_module_does_not_search_clients(): void
    {
        OrganizationEntitlement::query()->create([
            'organization_id' => $this->created->organization->id,
            'feature_key' => FeatureRegistry::CLIENTS,
            'enabled' => false,
            'reason' => 'test',
        ]);
        app(\App\Domain\Saas\EntitlementService::class)->flush();

        $results = $this->search($this->manager, 'yaw');

        $this->assertFalse($results->canSearchClients);
        $this->assertSame([], $this->clientNames($results));
        $this->assertSame(['Yaw Boateng'], $this->staffNames($results), 'staff are still searched');
    }

    #[Test]
    public function results_are_bounded_and_say_when_there_are_more(): void
    {
        foreach (range(1, 12) as $i) {
            $this->client('Zoe', sprintf('Match%02d', $i), "zoe{$i}@example.org");
        }
        foreach (range(1, 7) as $i) {
            $this->addStaff($this->created->organization, 'staff', user: $this->person("Sam Team{$i}", "sam{$i}@carebase.test"));
        }

        $zoe = $this->search($this->manager, 'zoe match');
        $this->assertCount(GlobalSearch::CLIENT_LIMIT, $zoe->clients->items);
        $this->assertTrue($zoe->clients->more);

        $sam = $this->search($this->manager, 'sam team');
        $this->assertCount(GlobalSearch::STAFF_LIMIT, $sam->staff->items);
        $this->assertTrue($sam->staff->more);

        $few = $this->search($this->manager, 'ama');
        $this->assertFalse($few->clients->more);
        $this->assertFalse($few->staff->more);
    }

    #[Test]
    public function another_organizations_clients_and_staff_are_never_found(): void
    {
        $other = $this->createOrganization();
        $this->addStaff($other->organization, 'clinician', user: $this->person('Kwame Mensah', 'kwame@elsewhere.test'));
        $this->clientIn($other->organization, ['first_name' => 'Yaw', 'last_name' => 'Boateng', 'email' => 'yaw@elsewhere.test', 'phone' => null]);
        $this->clientIn($other->organization, ['first_name' => 'Zed', 'last_name' => 'Foreign', 'email' => 'zed@elsewhere.test', 'phone' => null]);
        $this->addStaff($other->organization, 'clinician', user: $this->person('Zelda Foreign', 'zelda@elsewhere.test'));

        $mine = $this->search($this->manager, 'yaw');
        $this->assertSame([$this->created->organization->id], $mine->clients->items->pluck('organization_id')->unique()->all());

        $this->assertSame([], $this->clientNames($this->search($this->manager, 'foreign')));
        $this->assertSame([], $this->staffNames($this->search($this->manager, 'foreign')));
        $this->assertSame(['Kwame Mensah'], $this->staffNames($this->search($this->manager, 'kwame')), 'one Kwame here, not two');
        $this->assertSame([], $this->staffNames($this->search($this->manager, 'elsewhere.test')));

        $theirs = $this->actAs($other->ownerMembership, $other->organization, fn () => app(GlobalSearch::class)($other->ownerMembership, 'foreign'));
        $this->assertSame(['Zed Foreign'], $this->clientNames($theirs));
        $this->assertSame(['Zelda Foreign'], $this->staffNames($theirs));
    }

    #[Test]
    public function a_member_of_another_organization_cannot_be_the_searcher(): void
    {
        $other = $this->createOrganization();

        $this->expectException(TenantMismatch::class);

        $this->inTenant($this->created->organization, fn () => app(GlobalSearch::class)($other->ownerMembership, 'yaw'));
    }

    #[Test]
    public function wildcards_typed_by_a_user_are_literal_for_staff_too(): void
    {
        $this->assertSame([], $this->staffNames($this->search($this->manager, '%')));
        $this->assertSame([], $this->staffNames($this->search($this->manager, '_wame')));
    }

    #[Test]
    public function nothing_typed_finds_nothing_and_says_which_sections_are_open(): void
    {
        foreach ([null, '', '   '] as $nothing) {
            $results = $this->search($this->manager, $nothing);

            $this->assertTrue($results->isEmpty());
            $this->assertSame('', $results->term);
            $this->assertTrue($results->canSearchClients);
            $this->assertTrue($results->canSearchStaff);
        }
    }

    #[Test]
    public function a_member_who_is_no_longer_active_may_search_nothing(): void
    {
        $this->inTenant($this->created->organization, fn () => OrganizationMembership::query()->whereKey($this->manager->id)->first()->forceFill(['status' => 'suspended'])->save());
        $suspended = $this->membershipRecord($this->manager->id);

        [$results, $statements] = $this->actAs($suspended, $this->created->organization, fn () => $this->recordingQueries(
            fn () => app(GlobalSearch::class)($suspended, 'yaw'),
        ));

        $this->assertFalse($results->canSearchClients);
        $this->assertFalse($results->canSearchStaff);
        $this->assertTrue($results->isEmpty());
        foreach ($statements as $sql) {
            $this->assertStringNotContainsString('from "clients"', $sql);
            $this->assertStringNotContainsString('"users"', $sql);
        }
    }

    #[Test]
    public function the_term_is_trimmed_and_capped(): void
    {
        $this->assertSame('yaw', $this->search($this->manager, "   yaw  \n")->term);
        $this->assertSame(100, mb_strlen($this->search($this->manager, str_repeat('a', 500))->term));
    }

    #[Test]
    public function a_search_costs_a_fixed_number_of_queries_however_much_it_finds(): void
    {
        foreach (range(1, 12) as $i) {
            $this->client('Zoe', sprintf('Match%02d', $i), "zoe{$i}@example.org");
            $this->addStaff($this->created->organization, 'staff', user: $this->person("Zoe Staff{$i}", "zoestaff{$i}@carebase.test"));
        }

        $statementsFor = function (string $term): array {
            [, $statements] = $this->actAs($this->manager, $this->created->organization, fn () => $this->recordingQueries(
                fn () => app(GlobalSearch::class)($this->manager, $term),
            ));

            return $statements;
        };

        $statementsFor('warm-up');   // the permission and plan lookups are memoised per request: measure the steady state
        $one = $statementsFor('zoe staff1');
        $many = $statementsFor('zoe');

        $this->assertGreaterThan(1, $this->actAs($this->manager, $this->created->organization, fn () => app(GlobalSearch::class)($this->manager, 'zoe')->staff->items->count()));
        $this->assertCount(count($one), $many, 'the number of queries does not grow with the number of results');
        // clients + staff + the staff members' users in one go
        $this->assertLessThanOrEqual(3, count($many), implode("\n", $many));
    }
}
