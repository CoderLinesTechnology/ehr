<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\ClientDirectory;
use App\Domain\Clients\ClientListFilters;
use App\Domain\Clients\ClientStatus;
use App\Models\Client;
use App\Models\OrganizationMembership;
use PHPUnit\Framework\Attributes\Test;

class ClientDirectoryTest extends ClientsTestCase
{
    private $created;

    private OrganizationMembership $dr1;

    private OrganizationMembership $dr2;

    private OrganizationMembership $supervisor;

    /** @var array<string, Client> */
    private array $c = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();
        $org = $this->created->organization;

        $this->dr1 = $this->addStaff($org, 'clinician');
        $this->dr2 = $this->addStaff($org, 'clinician');
        $this->supervisor = $this->addStaff($org, 'supervisor');

        // number: 1..8 in this order; created_at deliberately NOT in number order
        $this->add('adams', 'Adams', ClientStatus::Active, '2026-03-05 10:00:00', $this->dr1);
        $this->add('brown', 'Brown', ClientStatus::Active, '2026-03-01 10:00:00', $this->dr2);
        $this->add('clark', 'Clark', ClientStatus::Inactive, '2026-03-03 10:00:00', $this->dr1);
        $this->add('davis', 'Davis', ClientStatus::Archived, '2026-03-04 10:00:00', $this->dr1);
        $this->add('evans', 'Evans', ClientStatus::Active, '2026-03-02 10:00:00', null);
        $this->add('demo_a', 'Adler', ClientStatus::Active, '2026-03-06 10:00:00', $this->dr1, demo: true);
        $this->add('same1', 'Same', ClientStatus::Active, '2026-03-07 10:00:00', null, first: 'Amy');
        $this->add('same2', 'Same', ClientStatus::Active, '2026-03-07 10:00:00', null, first: 'Amy');   // identical in every sorted column
    }

    private function add(string $key, string $last, ClientStatus $status, string $createdAt, ?OrganizationMembership $clinician, bool $demo = false, string $first = 'Test'): void
    {
        $factory = $demo ? Client::factory()->demo() : Client::factory();

        $this->c[$key] = $this->inTenant($this->created->organization, fn () => $factory->status($status)->create([
            'first_name' => $first, 'last_name' => $last, 'email' => "{$key}@example.org", 'phone' => null,
            'primary_clinician_membership_id' => $clinician?->id,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]));
    }

    /** @return list<string> client keys in list order */
    private function list(OrganizationMembership $viewer, array $query = []): array
    {
        $ids = $this->actAs($viewer, $this->created->organization, fn () => app(ClientDirectory::class)
            ->query($viewer, ClientListFilters::from($query))->get()->pluck('id')->all());

        $keyOf = array_flip(array_map(fn (Client $client) => $client->id, $this->c));   // id => key

        return array_map(fn ($id) => $keyOf[$id], $ids);
    }

    #[Test]
    public function by_default_it_lists_active_and_inactive_clients_live_and_demo_by_last_name(): void
    {
        $this->assertSame(['adams', 'demo_a', 'brown', 'clark', 'evans', 'same1', 'same2'], $this->list($this->supervisor), 'Adams, Adler, Brown...; archived Davis is not listed');
    }

    #[Test]
    public function the_status_filter(): void
    {
        $this->assertSame(['davis'], $this->list($this->supervisor, ['status' => 'archived']));
        $this->assertSame(['clark'], $this->list($this->supervisor, ['status' => 'inactive']));
        $this->assertSame(['adams', 'demo_a', 'brown', 'evans', 'same1', 'same2'], $this->list($this->supervisor, ['status' => 'active']));
        $this->assertCount(8, $this->list($this->supervisor, ['status' => 'all']));
    }

    #[Test]
    public function the_records_filter_separates_live_from_demo(): void
    {
        $this->assertSame(['demo_a'], $this->list($this->supervisor, ['records' => 'demo']));
        $this->assertSame(['adams', 'brown', 'clark', 'evans', 'same1', 'same2'], $this->list($this->supervisor, ['records' => 'live']));
        $this->assertCount(7, $this->list($this->supervisor, ['records' => 'all']));
    }

    #[Test]
    public function the_clinician_filter_takes_a_membership_or_none(): void
    {
        $this->assertSame(['adams', 'demo_a', 'clark'], $this->list($this->supervisor, ['clinician' => $this->dr1->id]));
        $this->assertSame(['brown'], $this->list($this->supervisor, ['clinician' => $this->dr2->id]));
        $this->assertSame(['evans', 'same1', 'same2'], $this->list($this->supervisor, ['clinician' => 'none']));
        $this->assertSame([], $this->list($this->supervisor, ['clinician' => '0198a2f4-7c3e-7b1d-9a52-3f6a8c0d1e24']));
    }

    #[Test]
    public function the_search_term_narrows_the_list(): void
    {
        $this->assertSame(['brown'], $this->list($this->supervisor, ['q' => 'brown']));
        $this->assertSame(['evans'], $this->list($this->supervisor, ['q' => 'C-00005']));
        $this->assertSame([], $this->list($this->supervisor, ['q' => 'davis']), 'archived clients are hidden unless the status filter allows them');
        $this->assertSame(['davis'], $this->list($this->supervisor, ['q' => 'davis', 'status' => 'archived']));
    }

    #[Test]
    public function the_sorts(): void
    {
        $this->assertSame(['same2', 'same1', 'evans', 'clark', 'brown', 'demo_a', 'adams'], $this->list($this->supervisor, ['sort' => 'last_name', 'direction' => 'desc']));
        $this->assertSame(['adams', 'brown', 'clark', 'evans', 'demo_a', 'same1', 'same2'], $this->list($this->supervisor, ['sort' => 'client_number']));
        $this->assertSame(['same2', 'same1', 'demo_a', 'evans', 'clark', 'brown'], array_slice($this->list($this->supervisor, ['sort' => 'client_number', 'direction' => 'desc']), 0, 6));
        $this->assertSame(['brown', 'evans', 'clark', 'adams', 'demo_a', 'same1', 'same2'], $this->list($this->supervisor, ['sort' => 'created']));
        $this->assertSame(['same2', 'same1', 'demo_a', 'adams', 'clark', 'evans', 'brown'], $this->list($this->supervisor, ['sort' => 'created', 'direction' => 'desc']));
    }

    #[Test]
    public function rows_identical_in_every_sorted_column_keep_a_stable_order_across_runs_and_directions(): void
    {
        $first = $this->list($this->supervisor);
        $this->assertSame($first, $this->list($this->supervisor));

        // ties break on id, ascending or descending with the sort
        $this->assertSame(['same1', 'same2'], array_slice($first, -2));
        $this->assertSame(['same2', 'same1'], array_slice($this->list($this->supervisor, ['direction' => 'desc']), 0, 2));
    }

    #[Test]
    public function a_clinician_is_limited_to_the_clients_they_may_see_whatever_the_filters(): void
    {
        $this->assertSame(['adams', 'demo_a', 'clark'], $this->list($this->dr1));
        $this->assertSame(['brown'], $this->list($this->dr2));

        // asking for somebody else's clients through a filter reveals nothing
        $this->assertSame([], $this->list($this->dr1, ['clinician' => $this->dr2->id]));
        $this->assertSame([], $this->list($this->dr1, ['q' => 'brown']));
        $this->assertSame([], $this->list($this->dr1, ['clinician' => 'none']));
    }

    #[Test]
    public function an_appointment_adds_a_client_to_the_clinicians_list(): void
    {
        $this->assertSame([], $this->list($this->dr2, ['q' => 'evans']));

        $this->bookAppointment($this->created->organization, $this->c['evans'], $this->dr2);

        $this->assertSame(['evans'], $this->list($this->dr2, ['q' => 'evans']));
    }

    #[Test]
    public function it_loads_only_the_columns_the_list_shows_and_the_clinician_with_their_user(): void
    {
        $client = $this->actAs($this->supervisor, $this->created->organization, fn () => app(ClientDirectory::class)
            ->query($this->supervisor, ClientListFilters::from(['q' => 'adams']))->first());

        foreach (['email', 'administrative_notes', 'address_line1', 'search_text', 'referral_source'] as $column) {
            $this->assertArrayNotHasKey($column, $client->getAttributes(), $column);
        }
        $this->assertTrue($client->relationLoaded('primaryClinician'));
        $this->assertTrue($client->primaryClinician->relationLoaded('user'));
        $this->assertSame($this->dr1->user->name, $client->primaryClinician->displayName());
        $this->assertMatchesRegularExpression('/^[A-Z]+-0*1$/', $client->formattedNumber());
    }

    #[Test]
    public function a_page_costs_the_same_number_of_queries_for_five_rows_or_fifty(): void
    {
        $page = function (int $perPage): int {
            [, $statements] = $this->actAs($this->supervisor, $this->created->organization, fn () => $this->recordingQueries(
                fn () => app(ClientDirectory::class)->query($this->supervisor, ClientListFilters::from(['status' => 'all']))->paginate($perPage),
            ));

            return count($statements);
        };

        foreach (range(1, 50) as $i) {
            $this->inTenant($this->created->organization, fn () => Client::factory()->create(['primary_clinician_membership_id' => $this->dr1->id]));
        }

        $page(1);   // warm the per-request permission memo
        $small = $page(5);
        $large = $page(50);

        $this->assertSame($small, $large, 'no query per row');
        $this->assertLessThanOrEqual(4, $large, 'clients, their count, the clinicians, their users');
    }

    #[Test]
    public function pages_of_25_follow_each_other_without_repeats(): void
    {
        foreach (range(1, 60) as $i) {
            $this->inTenant($this->created->organization, fn () => Client::factory()->create(['last_name' => sprintf('Page%02d', $i)]));
        }

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $paginator = $this->actAs($this->supervisor, $this->created->organization, fn () => app(ClientDirectory::class)
                ->query($this->supervisor, ClientListFilters::from(['q' => 'page', 'sort' => 'last_name']))->paginate(25, page: $page));

            $this->assertSame(60, $paginator->total());
            array_push($seen, ...$paginator->getCollection()->pluck('last_name')->all());
            $this->assertCount([1 => 25, 2 => 25, 3 => 10][$page], $paginator->items());
        }

        $this->assertSame(array_map(fn ($i) => sprintf('Page%02d', $i), range(1, 60)), $seen);
    }
}
