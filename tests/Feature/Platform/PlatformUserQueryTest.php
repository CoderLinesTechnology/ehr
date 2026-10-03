<?php

namespace Tests\Feature\Platform;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Identity\PlatformRoles\GrantPlatformRole;
use App\Domain\Platform\OrganizationStatus;
use App\Domain\Platform\Queries\PlatformAdministrator;
use App\Domain\Platform\Queries\PlatformUserListItem;
use App\Domain\Platform\Queries\PlatformUserQuery;
use App\Domain\Platform\Users\DisableUser;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

class PlatformUserQueryTest extends PlatformTestCase
{
    private function listUsers(array $filters = [], int $perPage = 25, ?int $page = 1)
    {
        return app(PlatformUserQuery::class)->paginate($filters, $perPage, $page);
    }

    /** @return list<string> */
    private function emails(array $filters = []): array
    {
        return array_map(fn (PlatformUserListItem $item) => $item->email, $this->listUsers($filters)->items());
    }

    #[Test]
    public function search_matches_name_or_email_ignoring_case_and_treats_wildcards_literally(): void
    {
        User::factory()->create(['name' => 'Ama Mensah', 'email' => 'ama@example.org']);
        User::factory()->create(['name' => 'Kofi Boateng', 'email' => 'kofi.b@example.org']);
        User::factory()->create(['name' => '100% Real', 'email' => 'real@example.org']);
        User::factory()->create(['name' => 'Under_score', 'email' => 'under@example.org']);
        User::factory()->create(['name' => 'UnderXscore', 'email' => 'underx@example.org']);

        $this->assertSame(['ama@example.org'], $this->emails(['q' => 'MENSAH']));
        $this->assertSame(['kofi.b@example.org'], $this->emails(['q' => 'KOFI.B@']));
        $this->assertSame(['real@example.org'], $this->emails(['q' => '%']), 'A lone percent sign is not "everything".');
        $this->assertSame(['under@example.org'], $this->emails(['q' => 'r_s']));
        $this->assertCount(5, $this->emails(['q' => '']));
        $this->assertCount(5, $this->emails(['q' => ['x']]));
    }

    #[Test]
    public function the_list_filters_by_status_and_by_platform_staff(): void
    {
        $this->platformUser('platform_support')->forceFill(['email' => 'support@example.org'])->save();
        User::factory()->create(['email' => 'plain@example.org']);
        User::factory()->disabled()->create(['email' => 'gone@example.org']);

        $this->assertEqualsCanonicalizing(['support@example.org', 'plain@example.org'], $this->emails(['status' => 'active']));
        $this->assertSame(['gone@example.org'], $this->emails(['status' => 'disabled']));
        $this->assertSame(['support@example.org'], $this->emails(['staff' => '1']));
        $this->assertSame(['support@example.org'], $this->emails(['staff' => true]));
        $this->assertCount(3, $this->emails(['staff' => '0']), 'Only a truthy value switches the filter on.');
        $this->assertCount(3, $this->emails(['status' => 'bogus']));
    }

    #[Test]
    public function sorting_is_whitelisted_and_accounts_that_never_signed_in_always_come_last(): void
    {
        User::factory()->create(['name' => 'Bravo', 'email' => 'b@example.org', 'last_login_at' => '2026-03-01 10:00:00', 'created_at' => '2026-01-02 10:00:00']);
        User::factory()->create(['name' => 'Alpha', 'email' => 'a@example.org', 'last_login_at' => null, 'created_at' => '2026-01-03 10:00:00']);
        User::factory()->create(['name' => 'Charlie', 'email' => 'c@example.org', 'last_login_at' => '2026-02-01 10:00:00', 'created_at' => '2026-01-01 10:00:00']);

        $this->assertSame(['a@example.org', 'b@example.org', 'c@example.org'], $this->emails(['sort' => 'name']));
        $this->assertSame(['c@example.org', 'b@example.org', 'a@example.org'], $this->emails(['sort' => 'email', 'direction' => 'desc']));
        $this->assertSame(['a@example.org', 'b@example.org', 'c@example.org'], $this->emails([]), 'Newest account first by default.');
        $this->assertSame(['b@example.org', 'c@example.org', 'a@example.org'], $this->emails(['sort' => 'last_login_at', 'direction' => 'desc']), 'Most recent sign-in first; never-signed-in last.');
        $this->assertSame(['c@example.org', 'b@example.org', 'a@example.org'], $this->emails(['sort' => 'last_login_at', 'direction' => 'asc']), 'Oldest first; never-signed-in still last.');
        $this->assertSame(['a@example.org', 'b@example.org', 'c@example.org'], $this->emails(['sort' => 'password; drop table users']));
    }

    #[Test]
    public function a_row_shows_organizations_roles_verification_and_sign_in_without_any_credentials(): void
    {
        $admin = $this->signInAsPlatform();
        $member = User::factory()->unverified()->create(['name' => 'Member Person', 'last_login_at' => '2026-05-05 08:00:00']);
        $one = $this->createOrganization()->organization;
        $two = $this->createOrganization()->organization;
        $three = $this->createOrganization()->organization;
        $this->addStaff($one, 'clinician', user: $member);
        $this->addStaff($two, 'receptionist', user: $member);
        $this->addStaff($three, 'clinician', ['status' => MembershipStatus::Deactivated], $member);
        app(GrantPlatformRole::class)(User::factory()->create(['name' => 'Staff Person']), 'platform_support', $admin, 'Support rota');
        app(GrantPlatformRole::class)(User::query()->where('name', 'Staff Person')->firstOrFail(), 'platform_admin', $admin, 'Also admin');

        $items = collect($this->listUsers()->items())->keyBy('name');

        $this->assertSame(2, $items['Member Person']->organizationsCount, 'Active memberships only: the deactivated one is not counted.');
        $this->assertFalse($items['Member Person']->emailVerified);
        $this->assertFalse($items['Member Person']->disabled);
        $this->assertSame('2026-05-05 08:00:00', $items['Member Person']->lastLoginAt->utc()->format('Y-m-d H:i:s'));
        $this->assertSame([], $items['Member Person']->platformRoles);
        $this->assertSame(['Platform Administrator', 'Platform Support'], $items['Staff Person']->platformRoles, 'Names, sorted.');
        $this->assertSame(0, $items['Staff Person']->organizationsCount);
        $this->assertNull($items[$admin->name]->lastLoginAt);
    }

    #[Test]
    public function the_list_never_loads_credentials_or_two_factor_material(): void
    {
        $properties = array_map(fn ($p) => $p->getName(), (new ReflectionClass(PlatformUserListItem::class))->getProperties());
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'email', 'disabled', 'emailVerified', 'organizationsCount', 'platformRoles', 'lastLoginAt', 'createdAt'],
            $properties,
            'Adding a field to the account list row is a decision: update this test with it.',
        );

        User::factory()->withTwoFactor()->create();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->listUsers();
        $this->listUsers(['q' => 'a', 'staff' => '1', 'sort' => 'last_login_at']);
        app(PlatformUserQuery::class)->administrators();
        $sql = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, '"users"'))->implode(' ');
        DB::disableQueryLog();

        $this->assertNotSame('', $sql);
        foreach (['"password"', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'select *'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql, "The account queries must not select {$forbidden}.");
        }
    }

    #[Test]
    public function the_list_is_paginated_and_costs_a_fixed_number_of_queries(): void
    {
        foreach (range(1, 3) as $i) {
            User::factory()->create(['name' => "Person {$i}"]);
        }
        $this->listUsers(perPage: 10);
        [$small, $smallCount] = $this->countQueries(fn () => $this->listUsers(perPage: 10));

        $organization = $this->createOrganization()->organization;
        foreach (range(4, 25) as $i) {
            $this->addStaff($organization, 'clinician', user: User::factory()->create(['name' => "Person {$i}"]));
        }
        [$large, $largeCount] = $this->countQueries(fn () => $this->listUsers(perPage: 10));

        $this->assertCount(3, $small->items());
        $this->assertCount(10, $large->items());
        $this->assertGreaterThan(10, $large->total());
        $this->assertSame($smallCount, $largeCount);
        $this->assertSame(3, $largeCount, 'Count, rows, platform roles.');
        $this->assertSame([], $this->listUsers([], 10, 99)->items());
    }

    // ── one account ─────────────────────────────────────────────────────

    #[Test]
    public function an_account_overview_names_its_organizations_statuses_and_role_names_and_nothing_inside_them(): void
    {
        $admin = $this->signInAsPlatform();
        $person = User::factory()->withTwoFactor()->create(['name' => 'Dr Ama', 'timezone' => 'Africa/Accra']);
        $alpha = $this->createOrganization(['name' => 'Alpha Practice'])->organization;
        $beta = $this->createOrganization(['name' => 'Beta Clinic'], status: OrganizationStatus::Trial)->organization;
        $this->makeClient($alpha, ['first_name' => 'Secretiva', 'last_name' => 'Patientsson']);
        $clinician = $this->addStaff($alpha, 'clinician', user: $person);
        $this->inTenant($alpha, fn () => $clinician->roles()->attach(Role::query()->forOrganization($alpha->id)->where('key', 'supervisor')->firstOrFail()->id));
        $this->addStaff($beta, 'receptionist', ['status' => MembershipStatus::Suspended], $person);

        $overview = app(PlatformUserQuery::class)->overview($person->fresh());

        $this->assertSame('Dr Ama', $overview->profile['name']);
        $this->assertTrue($overview->profile['two_factor_enabled']);
        $this->assertFalse($overview->profile['disabled']);
        $this->assertSame('Africa/Accra', $overview->profile['timezone']);

        $this->assertSame([
            ['organization' => 'Alpha Practice', 'slug' => $alpha->slug, 'organization_status' => 'active', 'status' => 'active', 'roles' => ['Clinical Supervisor', 'Clinician']],
            ['organization' => 'Beta Clinic', 'slug' => $beta->slug, 'organization_status' => 'trial', 'status' => 'suspended', 'roles' => ['Receptionist']],
        ], $overview->memberships);

        $this->assertSame([], $overview->platformRoles);
        $this->assertStringNotContainsString('Secretiva', json_encode($overview));
        $this->assertStringNotContainsString('Patientsson', json_encode($overview));
        $this->assertNotNull($admin);
    }

    #[Test]
    public function an_account_overview_lists_platform_roles_and_platform_activity_about_the_account(): void
    {
        $admin = $this->signInAsPlatform();
        $person = User::factory()->create(['name' => 'Joiner']);
        app(GrantPlatformRole::class)($person, 'platform_support', $admin, 'Rota');
        app(DisableUser::class)($person, $admin, 'Left the rota');
        // Activity in an organization about the same account must not appear.
        app(AuditLogger::class)->record('team.member_removed', $person, summary: 'Removed from a practice', context: AuditContext::Organization);

        $overview = app(PlatformUserQuery::class)->overview($person->fresh());

        $this->assertSame([['key' => 'platform_support', 'name' => 'Platform Support']], $overview->platformRoles);
        $this->assertTrue($overview->profile['disabled']);
        $this->assertSame(['user.disabled', 'platform.role_granted'], $overview->recentAudit->pluck('action')->all(), 'Newest first, platform activity only.');
    }

    #[Test]
    public function the_administrators_list_has_each_role_with_who_granted_it_and_whether_two_factor_is_on(): void
    {
        $first = $this->signInAsPlatform();
        $first->forceFill(['name' => 'Alice Root'])->save();
        $second = User::factory()->create(['name' => 'Bob Support']);
        $third = User::factory()->withTwoFactor()->create(['name' => 'Carol Both']);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 09:00:00'));
        app(GrantPlatformRole::class)($second, 'platform_support', $first->fresh(), 'Rota');
        app(GrantPlatformRole::class)($third, 'platform_support', $first->fresh(), 'Rota');
        app(GrantPlatformRole::class)($third, 'platform_admin', $first->fresh(), 'More');
        User::factory()->create(['name' => 'Nobody Special']);
        app(DisableUser::class)($third, $first->fresh(), 'On leave');

        $admins = app(PlatformUserQuery::class)->administrators();

        $this->assertContainsOnlyInstancesOf(PlatformAdministrator::class, $admins);
        $this->assertSame(['Alice Root', 'Bob Support', 'Carol Both'], $admins->pluck('name')->all(), 'Only holders of a platform role, by name.');

        $alice = $admins[0];
        $this->assertTrue($alice->twoFactorConfirmed);
        $this->assertFalse($alice->disabled);
        $this->assertSame(['Super Admin'], array_column($alice->roles, 'name'));
        $this->assertNull($alice->roles[0]['granted_by'], 'The test helper attached it without a grantor.');

        $bob = $admins[1];
        $this->assertFalse($bob->twoFactorConfirmed, 'Bob has not set up two-factor yet: the console will ask him.');
        $this->assertSame([['key' => 'platform_support', 'name' => 'Platform Support', 'granted_at' => $bob->roles[0]['granted_at'], 'granted_by' => 'Alice Root']], $bob->roles);
        $this->assertSame('2026-10-02 09:00:00', $bob->roles[0]['granted_at']->utc()->format('Y-m-d H:i:s'));

        $carol = $admins[2];
        $this->assertTrue($carol->disabled);
        $this->assertSame(['Platform Administrator', 'Platform Support'], array_column($carol->roles, 'name'));
        $this->assertSame(['Alice Root', 'Alice Root'], array_column($carol->roles, 'granted_by'));
    }

    #[Test]
    public function the_administrators_list_costs_three_queries_however_many_there_are(): void
    {
        $admin = $this->signInAsPlatform();
        foreach (range(1, 2) as $i) {
            app(GrantPlatformRole::class)(User::factory()->create(), 'platform_support', $admin, 'Rota');
        }
        app(PlatformUserQuery::class)->administrators();
        [, $few] = $this->countQueries(fn () => app(PlatformUserQuery::class)->administrators());

        foreach (range(1, 15) as $i) {
            app(GrantPlatformRole::class)(User::factory()->create(), $i % 2 ? 'platform_support' : 'platform_admin', $admin, 'Rota');
        }
        [$many, $manyCount] = $this->countQueries(fn () => app(PlatformUserQuery::class)->administrators());

        $this->assertCount(18, $many);
        $this->assertSame($few, $manyCount);
        $this->assertSame(3, $manyCount, 'Accounts, their roles, and the grantors\' names.');
        $this->assertNotNull(Role::query()->platform()->first());
    }
}
