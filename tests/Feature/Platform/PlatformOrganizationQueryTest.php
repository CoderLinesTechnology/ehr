<?php

namespace Tests\Feature\Platform;

use App\Domain\Identity\MembershipStatus;
use App\Domain\Platform\ChangeOrganizationStatus;
use App\Domain\Platform\OrganizationStatus;
use App\Domain\Platform\Queries\OrganizationListItem;
use App\Domain\Platform\Queries\PlatformOrganizationQuery;
use App\Domain\Saas\ChangeSubscriptionStatus;
use App\Domain\Saas\SubscriptionStatus;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

class PlatformOrganizationQueryTest extends PlatformTestCase
{
    private function listOrganizations(array $filters = [], int $perPage = 25, ?int $page = 1)
    {
        return app(PlatformOrganizationQuery::class)->paginate($filters, $perPage, $page);
    }

    /** @return list<string> */
    private function names(array $filters = [], int $perPage = 25): array
    {
        return array_map(fn (OrganizationListItem $item) => $item->name, $this->listOrganizations($filters, $perPage)->items());
    }

    private function organization(string $name, array $profile = [], string $plan = 'starter', OrganizationStatus $status = OrganizationStatus::Active, ?CarbonImmutable $createdAt = null): Organization
    {
        $organization = $this->createOrganization(['name' => $name] + $profile, $plan, $status)->organization;
        if ($createdAt !== null) {
            $organization->forceFill(['created_at' => $createdAt])->save();
        }

        return $organization;
    }

    #[Test]
    public function search_matches_name_address_or_email_ignoring_case(): void
    {
        $this->signInAsPlatform();
        $this->organization('Alpha Wellness', ['slug' => 'alpha-wellness', 'email' => 'contact@alpha.example']);
        $this->organization('Beta Counselling', ['slug' => 'beta-hub', 'email' => 'hello@beta.example']);
        $this->organization('Gamma Clinic', ['slug' => 'gamma', 'email' => 'team@gamma.example']);

        $this->assertSame(['Alpha Wellness'], $this->names(['q' => 'wellness']), 'By name.');
        $this->assertSame(['Beta Counselling'], $this->names(['q' => 'BETA-HUB']), 'By address, any case.');
        $this->assertSame(['Gamma Clinic'], $this->names(['q' => 'Team@Gamma']), 'By email.');
        $this->assertCount(3, $this->names(['q' => '']), 'An empty term filters nothing.');
        $this->assertCount(3, $this->names(['q' => ['not', 'a', 'string']]), 'A non-string term is no term.');
        $this->assertSame([], $this->names(['q' => 'nothing like this']));
    }

    #[Test]
    public function the_wildcards_in_a_search_term_are_ordinary_characters(): void
    {
        $this->signInAsPlatform();
        $this->organization('100% Care');
        $this->organization('1000 Care');
        $this->organization('Under_score Clinic');
        $this->organization('UnderXscore Clinic');

        $this->assertSame(['100% Care'], $this->names(['q' => '100%']));
        $this->assertSame(['100% Care'], $this->names(['q' => '%']), 'A lone percent sign is not "everything".');
        $this->assertSame(['Under_score Clinic'], $this->names(['q' => 'r_s']), 'An underscore is not "any character".');
        $this->assertSame([], $this->names(['q' => '\\']), 'A backslash is just a backslash.');
    }

    #[Test]
    public function the_list_filters_by_status_and_by_plan_and_ignores_values_it_does_not_know(): void
    {
        $admin = $this->signInAsPlatform();
        $this->organization('Starter Active', plan: 'starter');
        $this->organization('Pro Active', plan: 'professional');
        $this->organization('Starter Trial', plan: 'starter', status: OrganizationStatus::Trial);
        $suspended = $this->organization('Pro Suspended', plan: 'professional');
        app(ChangeOrganizationStatus::class)($suspended, OrganizationStatus::Suspended, $admin, 'Unpaid');
        $cancelled = $this->organization('No Plan Left', plan: 'advanced');
        app(ChangeSubscriptionStatus::class)($cancelled, SubscriptionStatus::Cancelled, $admin, 'Left');

        $this->assertEqualsCanonicalizing(['Pro Suspended'], $this->names(['status' => 'suspended']));
        $this->assertEqualsCanonicalizing(['Starter Trial'], $this->names(['status' => 'trial']));
        $this->assertEqualsCanonicalizing(['Starter Active', 'Starter Trial'], $this->names(['plan' => 'starter']));
        $this->assertEqualsCanonicalizing(['Pro Active', 'Pro Suspended'], $this->names(['plan' => 'professional']));
        $this->assertEqualsCanonicalizing(['Pro Active'], $this->names(['plan' => 'professional', 'status' => 'active']), 'Filters combine.');
        $this->assertSame(['No Plan Left'], $this->names(['plan' => PlatformOrganizationQuery::NO_PLAN]), 'No live subscription.');
        $this->assertSame([], $this->names(['plan' => 'advanced']), 'A cancelled subscription is not a plan the organization is on.');
        $this->assertSame([], $this->names(['plan' => 'no_such_plan']));

        $this->assertCount(5, $this->names(['status' => 'bogus']), 'An unknown status filters nothing.');
        $this->assertCount(5, $this->names(['plan' => 'Not A Key!']), 'A malformed plan filter filters nothing.');
        $this->assertCount(5, $this->names(['status' => ['active']]));
    }

    #[Test]
    public function sorting_uses_a_whitelist_and_a_stable_tie_break(): void
    {
        $this->signInAsPlatform();
        $this->organization('Bravo', createdAt: CarbonImmutable::parse('2026-01-02 10:00:00'));
        $this->organization('Alpha', createdAt: CarbonImmutable::parse('2026-01-03 10:00:00'));
        $this->organization('Charlie', createdAt: CarbonImmutable::parse('2026-01-01 10:00:00'));

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $this->names(['sort' => 'name']), 'Name defaults to ascending.');
        $this->assertSame(['Charlie', 'Bravo', 'Alpha'], $this->names(['sort' => 'name', 'direction' => 'desc']));
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $this->names([]), 'Newest first by default.');
        $this->assertSame(['Charlie', 'Bravo', 'Alpha'], $this->names(['sort' => 'created_at', 'direction' => 'asc']));

        // A column that is not whitelisted is never put in the query.
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $this->names(['sort' => 'password; drop table organizations']));
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $this->names(['sort' => 'status', 'direction' => 'sideways']));
        $this->assertSame(3, Organization::query()->count());
    }

    #[Test]
    public function the_list_is_paginated_and_each_row_is_a_small_read_model(): void
    {
        $this->signInAsPlatform();
        foreach (range(1, 5) as $i) {
            $this->organization("Org {$i}", createdAt: CarbonImmutable::parse('2026-01-01 10:00:00')->addDays($i));
        }

        $first = $this->listOrganizations(['sort' => 'created_at', 'direction' => 'asc'], perPage: 2, page: 1);
        $this->assertSame(5, $first->total());
        $this->assertSame(3, $first->lastPage());
        $this->assertCount(2, $first->items());
        $this->assertContainsOnlyInstancesOf(OrganizationListItem::class, $first->items());
        $this->assertSame(['Org 1', 'Org 2'], array_map(fn ($i) => $i->name, $first->items()));

        $last = $this->listOrganizations(['sort' => 'created_at', 'direction' => 'asc'], perPage: 2, page: 3);
        $this->assertSame(['Org 5'], array_map(fn ($i) => $i->name, $last->items()));
        $this->assertSame([], $this->listOrganizations([], perPage: 2, page: 9)->items(), 'A page past the end is empty, not an error.');

        $this->assertSame(100, $this->listOrganizations([], perPage: 5000, page: 1)->perPage(), 'The page size is capped.');
    }

    #[Test]
    public function a_row_shows_the_plan_the_subscription_status_and_the_staff_seats(): void
    {
        $this->signInAsPlatform();
        $trial = $this->organization('Trialling', plan: 'professional', status: OrganizationStatus::Trial);
        $busy = $this->organization('Busy Practice', plan: 'starter');
        $this->addStaff($busy, 'clinician');
        $this->addStaff($busy, 'clinician', ['status' => MembershipStatus::Invited, 'invited_email' => 'pending@example.org']);
        $this->addStaff($busy, 'clinician', ['status' => MembershipStatus::Deactivated]);
        $this->addStaff($busy, 'clinician', ['status' => MembershipStatus::Suspended]);

        $items = collect($this->listOrganizations()->items())->keyBy('name');

        $this->assertSame('professional', $items['Trialling']->planKey);
        $this->assertSame('Professional', $items['Trialling']->planName);
        $this->assertSame(SubscriptionStatus::Trialing, $items['Trialling']->subscriptionStatus);
        $this->assertSame(OrganizationStatus::Trial, $items['Trialling']->status);
        $this->assertSame(1, $items['Trialling']->staffCount, 'Just the invited-then-active owner.');

        $this->assertSame('Starter', $items['Busy Practice']->planName);
        $this->assertSame(SubscriptionStatus::Active, $items['Busy Practice']->subscriptionStatus);
        $this->assertSame(3, $items['Busy Practice']->staffCount, 'Owner, one active and one invited: deactivated and suspended people do not hold a seat.');
        $this->assertNotNull($items['Busy Practice']->createdAt);
        $this->assertNotNull($trial);
    }

    #[Test]
    public function an_organization_without_a_live_subscription_shows_no_plan(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->organization('Lapsed');
        app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::Cancelled, $admin, 'Left');

        $item = $this->listOrganizations()->items()[0];

        $this->assertNull($item->planKey);
        $this->assertNull($item->planName);
        $this->assertNull($item->subscriptionStatus);
    }

    #[Test]
    public function a_page_costs_the_same_number_of_queries_whatever_the_number_of_organizations(): void
    {
        $this->signInAsPlatform();
        foreach (range(1, 3) as $i) {
            $organization = $this->organization("Small {$i}");
            $this->addStaff($organization, 'clinician');
        }
        $this->listOrganizations(perPage: 10); // warm the once-per-process loads

        [$small, $smallCount] = $this->countQueries(fn () => $this->listOrganizations(perPage: 10));

        foreach (range(1, 12) as $i) {
            $organization = $this->organization("Large {$i}", plan: $i % 2 === 0 ? 'professional' : 'starter');
            $this->addStaff($organization, 'clinician');
            $this->addStaff($organization, 'receptionist');
        }
        [$large, $largeCount] = $this->countQueries(fn () => $this->listOrganizations(perPage: 10));

        $this->assertCount(3, $small->items());
        $this->assertCount(10, $large->items());
        $this->assertSame($smallCount, $largeCount, 'No query per row.');
        $this->assertSame(5, $largeCount, 'Count, rows, live subscriptions, plans, staff counts.');
    }

    #[Test]
    public function a_row_exposes_only_what_the_list_shows(): void
    {
        $properties = array_map(fn ($p) => $p->getName(), (new ReflectionClass(OrganizationListItem::class))->getProperties());

        $this->assertEqualsCanonicalizing(
            ['slug', 'name', 'email', 'status', 'planKey', 'planName', 'subscriptionStatus', 'staffCount', 'createdAt'],
            $properties,
            'Adding a field to the list row is a decision: update this test with it.',
        );

        // The rows are mapped from selected columns only: the SQL never asks for the rest of the organization row.
        $this->signInAsPlatform();
        $this->organization('Selective');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->listOrganizations();
        $sql = collect(DB::getQueryLog())->pluck('query')->first(fn ($q) => str_contains($q, 'from "organizations"') && ! str_contains($q, 'count(*)'));
        DB::disableQueryLog();

        $this->assertNotNull($sql);
        $this->assertStringNotContainsString('select *', $sql);
        foreach (['legal_name', 'phone', 'address_line1', 'custom_domain', 'logo_path'] as $column) {
            $this->assertStringNotContainsString($column, $sql, "{$column} is not needed by the list.");
        }
    }
}
