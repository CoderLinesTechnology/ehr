<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\ClientDirectory;
use App\Domain\Clients\ClientSearch;
use App\Domain\Clients\ClientStatus;
use App\Domain\Clients\SearchHits;
use App\Domain\Tenancy\TenantMismatch;
use App\Models\Client;
use App\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class ClientSearchTest extends ClientsTestCase
{
    private $created;

    private OrganizationMembership $dr1;

    private OrganizationMembership $supervisor;

    private OrganizationMembership $nobody;

    /** @var array<int, Client> client number => client */
    private array $n = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();
        $org = $this->created->organization;

        $this->dr1 = $this->addStaff($org, 'clinician');
        $this->supervisor = $this->addStaff($org, 'supervisor');
        $this->nobody = $this->addStaff($org, 'staff');

        // A fixed roster: every name, e-mail and phone is spelled out so nothing matches by accident.
        $this->person('Ama', 'Owusu', 'ama.owusu@example.org', '+233244100001', ['primary_clinician_membership_id' => $this->dr1->id]);   // 1
        $this->person('Kofi', 'Mensah', 'kofi@example.org', '+233201234567', ['primary_clinician_membership_id' => $this->dr1->id]);        // 2
        $this->person('Efua', 'Asante', 'efua@example.org', '+233559999999', ['preferred_name' => 'Efe']);                                  // 3
        $this->person('Ama', 'Boateng', 'a.boateng@example.org', '+447911123456');                                                          // 4
        $this->person('Yaw', 'Boateng', 'yaw@example.org', null);                                                                           // 5
        foreach (range(6, 11) as $filler) {
            $this->person("Filler{$filler}", 'Zzz', "f{$filler}@fill.test", null);                                                          // 6-11
        }
        $this->person('Esi', 'Quartey', 'esi@example.org', null);                                                                           // 12
        $this->person('Archie', 'Boateng', 'archie@example.org', null, [], ClientStatus::Archived);                                         // 13
        $this->n[14] = $this->demoClientIn($org, ['first_name' => 'Demi', 'last_name' => 'Demo', 'email' => 'demi@example.org', 'phone' => null]); // 14

        $this->bookAppointment($org, $this->n[4], $this->dr1);   // dr1 has seen Ama Boateng
    }

    /** @param array<string, mixed> $extra */
    private function person(string $first, string $last, ?string $email, ?string $phone, array $extra = [], ClientStatus $status = ClientStatus::Active): Client
    {
        $client = $this->inTenant($this->created->organization, fn () => Client::factory()->status($status)->create([
            'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone,
        ] + $extra));

        return $this->n[$client->client_number] = $client;
    }

    /** @return list<int> client numbers found, in order */
    private function find(OrganizationMembership $member, ?string $term, int $limit = 10): array
    {
        return $this->hits($member, $term, $limit)->items->map->client_number->all();
    }

    private function hits(OrganizationMembership $member, ?string $term, int $limit = 10): SearchHits
    {
        return $this->actAs($member, $this->created->organization, fn () => app(ClientSearch::class)->find($member, $term, $limit));
    }

    // ── what staff type ─────────────────────────────────────────────────

    #[Test]
    public function it_finds_by_first_or_last_name_and_orders_non_archived_first_then_by_name(): void
    {
        $this->assertSame([4, 1], $this->find($this->supervisor, 'ama'), 'Boateng before Owusu');
        $this->assertSame([1], $this->find($this->supervisor, 'owusu'));
        $this->assertSame([4, 5, 13], $this->find($this->supervisor, 'boateng'), 'the archived one last');
        $this->assertSame([1], $this->find($this->supervisor, 'OWUSU'), 'case does not matter');
    }

    #[Test]
    public function every_word_must_match_in_any_order(): void
    {
        $this->assertSame([1], $this->find($this->supervisor, 'Ama Owusu'));
        $this->assertSame([1], $this->find($this->supervisor, 'owusu ama'));
        $this->assertSame([1], $this->find($this->supervisor, "  Ama    Owusu "), 'however the spaces fall');
        $this->assertSame([], $this->find($this->supervisor, 'Ama Mensah'));
    }

    #[Test]
    public function it_finds_by_preferred_name_and_by_email(): void
    {
        $this->assertSame([3], $this->find($this->supervisor, 'efe'));
        $this->assertSame([1], $this->find($this->supervisor, 'ama.owusu@example.org'));
        $this->assertSame([1], $this->find($this->supervisor, 'ama.owusu@'));
        $this->assertContains(2, $this->find($this->supervisor, '@example.org'));
    }

    #[Test]
    public function a_term_under_three_characters_is_a_name_prefix_not_a_substring(): void
    {
        $this->assertSame([4, 1], $this->find($this->supervisor, 'am'), 'first names starting "am"');
        $this->assertSame([4, 5, 13], $this->find($this->supervisor, 'bo'), 'last names starting "bo"');
        $this->assertSame([3, 12], $this->find($this->supervisor, 'e'), 'Efua, Esi');
        $this->assertSame([3], $this->find($this->supervisor, 'ef'), 'a preferred name counts too ("Efe" and "Efua")');
        $this->assertSame([], $this->find($this->supervisor, 'ma'), 'not a substring match: Ama contains "ma" but does not start with it');
    }

    #[Test]
    public function local_phone_numbers_find_the_stored_international_number(): void
    {
        foreach (['0244100001', '024 410 0001', '024-410-0001', '(024) 410 0001', '0244', '024 410', '244100001', '+233 24 410 0001', '+233244100001', '00233 244 100 001'] as $typed) {
            $this->assertSame([1], $this->find($this->supervisor, $typed), "typed as [{$typed}]");
        }

        $this->assertSame([2], $this->find($this->supervisor, '0201234567'));
        $this->assertSame([3], $this->find($this->supervisor, '055 999 9999'));
    }

    #[Test]
    public function a_phone_number_of_another_country_is_found_by_its_local_digits_too(): void
    {
        $this->assertSame([4], $this->find($this->supervisor, '+44 7911 123456'));
        $this->assertSame([4], $this->find($this->supervisor, '07911 123456'));
    }

    #[Test]
    public function a_term_with_a_letter_is_never_rewritten_as_a_phone_number(): void
    {
        // "0244a" is text: it must match as typed (nobody has it), not become the digits 244.
        $this->assertSame([], $this->find($this->supervisor, '0244a'));
    }

    #[Test]
    public function it_finds_by_client_number_in_any_of_the_ways_it_is_written(): void
    {
        foreach (['CL-0012', 'cl-12', 'C-00012', 'c-00012', 'C 12', 'c12', '0012', '00012', '12'] as $typed) {
            $this->assertSame([12], $this->find($this->supervisor, $typed), "typed as [{$typed}]");
        }

        $this->assertSame([2], $this->find($this->supervisor, '2'), 'number 2, not everyone whose phone contains a 2');
        $this->assertSame([], $this->find($this->supervisor, 'C-00099'));
    }

    #[Test]
    public function a_longer_number_is_a_client_number_or_part_of_a_phone_number_with_the_exact_number_first(): void
    {
        // Make client number 100, whose number also appears inside Ama Owusu's phone (+233244100001).
        DB::table('organization_counters')->where('organization_id', $this->created->organization->id)->where('key', 'client')->update(['value' => 99]);
        $hundred = $this->person('Hana', 'Hundred', 'hana@example.org', null);
        $this->assertSame(100, $hundred->client_number);

        $this->assertSame([100, 1], $this->find($this->supervisor, '100'));
    }

    #[Test]
    public function like_wildcards_typed_by_a_user_are_literal(): void
    {
        $this->assertSame([], $this->find($this->supervisor, '%'), 'a lone % finds nothing, not everyone');
        $this->assertSame([], $this->find($this->supervisor, 'A%'), 'not "everyone starting with A"');
        $this->assertSame([], $this->find($this->supervisor, '_ma'), '_ is not "any character"');
        $this->assertSame([], $this->find($this->supervisor, '%ama'));
        $this->assertSame([], $this->find($this->supervisor, 'am\\'), 'a backslash is just a backslash');
    }

    #[Test]
    public function nothing_typed_or_nothing_matchable_finds_nothing_without_touching_the_database(): void
    {
        foreach ([null, '', '   ', '+', '--', '0', '000'] as $nothing) {
            [$hits, $statements] = $this->actAs($this->supervisor, $this->created->organization, fn () => $this->recordingQueries(
                fn () => app(ClientSearch::class)->find($this->supervisor, $nothing),
            ));

            $this->assertTrue($hits->isEmpty(), var_export($nothing, true));
            $this->assertFalse($hits->more);
            $this->assertSame([], $statements, 'no query for '.var_export($nothing, true));
        }
    }

    #[Test]
    public function archived_and_demo_clients_are_found_and_identifiable(): void
    {
        $hits = $this->hits($this->supervisor, 'archie')->items;
        $this->assertSame(ClientStatus::Archived, $hits->first()->status);

        $demo = $this->hits($this->supervisor, 'demi')->items->first();
        $this->assertTrue($demo->isDemo());
    }

    // ── who may be found ────────────────────────────────────────────────

    #[Test]
    public function a_clinician_finds_only_the_clients_they_may_see(): void
    {
        // own: 1, 2 · appointment: 4 · not theirs: 3, 5, 12, ...
        $this->assertSame([4, 1], $this->find($this->dr1, 'ama'));
        $this->assertSame([4], $this->find($this->dr1, 'boateng'), 'Yaw and Archie Boateng are not dr1\'s clients');
        $this->assertSame([], $this->find($this->dr1, 'efua'));
        $this->assertSame([], $this->find($this->dr1, '055 999 9999'), 'not by phone either');
        $this->assertSame([], $this->find($this->dr1, 'C-00012'), 'not by number either');
        $this->assertSame([], $this->find($this->dr1, 'efe'));
    }

    #[Test]
    public function without_any_client_permission_nothing_is_found_and_nothing_is_queried(): void
    {
        [$hits, $statements] = $this->actAs($this->nobody, $this->created->organization, fn () => $this->recordingQueries(
            fn () => app(ClientSearch::class)->find($this->nobody, 'ama'),
        ));

        $this->assertTrue($hits->isEmpty());
        // only the membership's permissions were consulted
        $this->assertCount(1, $statements, implode("\n", $statements));
    }

    #[Test]
    public function another_organizations_clients_are_never_found(): void
    {
        $other = $this->createOrganization();
        $this->inTenant($other->organization, fn () => Client::factory()->create([
            'first_name' => 'Ama', 'last_name' => 'Owusu', 'email' => 'ama.owusu@example.org', 'phone' => '+233244100001',
        ]));
        $foreignNumber12 = $this->inTenant($other->organization, function () {
            DB::table('organization_counters')->where('organization_id', tenant()->id())->where('key', 'client')->update(['value' => 11]);

            return Client::factory()->create(['first_name' => 'Zed', 'last_name' => 'Foreign', 'email' => 'zed@example.org', 'phone' => null]);
        });
        $this->assertSame(12, $foreignNumber12->client_number);

        // Same name, e-mail and phone exist in the other organization: ours finds only our own.
        $ids = $this->hits($this->supervisor, 'ama owusu')->items->pluck('organization_id')->unique()->all();
        $this->assertSame([$this->created->organization->id], $ids);
        $this->assertCount(1, $this->hits($this->supervisor, '0244100001')->items);
        $this->assertSame([12], $this->find($this->supervisor, 'C-00012'));
        $this->assertSame(['Quartey'], $this->hits($this->supervisor, '12')->items->pluck('last_name')->all(), 'number 12 here is ours, not theirs');
        $this->assertSame([], $this->find($this->supervisor, 'foreign'));

        // and the other way round
        $foreignSearcher = $other->ownerMembership;
        $found = $this->actAs($foreignSearcher, $other->organization, fn () => app(ClientSearch::class)->find($foreignSearcher, 'ama owusu')->items->pluck('organization_id')->unique()->all());
        $this->assertSame([$other->organization->id], $found);
    }

    #[Test]
    public function a_member_of_another_organization_cannot_be_the_searcher(): void
    {
        $other = $this->createOrganization();

        $this->expectException(TenantMismatch::class);

        $this->inTenant($this->created->organization, fn () => app(ClientSearch::class)->find($other->ownerMembership, 'ama'));
    }

    // ── bounds and shape ────────────────────────────────────────────────

    #[Test]
    public function results_are_bounded_and_say_whether_there_are_more(): void
    {
        foreach (range(1, 12) as $i) {
            $this->person('Zoe', sprintf('Match%02d', $i), "zoe{$i}@example.org", null);
        }

        $five = $this->hits($this->supervisor, 'zoe match', 5);
        $this->assertCount(5, $five->items);
        $this->assertTrue($five->more);

        $exactly = $this->hits($this->supervisor, 'zoe match', 12);
        $this->assertCount(12, $exactly->items);
        $this->assertFalse($exactly->more, 'exactly as many as the limit is not "more"');

        $this->assertCount(10, $this->hits($this->supervisor, 'zoe match')->items, 'the default limit is 10');
        $this->assertCount(12, $this->hits($this->supervisor, 'zoe match', 9999)->items, 'a huge limit is clamped, not honoured');
        $this->assertCount(1, $this->hits($this->supervisor, 'zoe match', 0)->items, 'and a silly small one raised to 1');
    }

    #[Test]
    public function it_returns_only_the_columns_a_result_list_shows(): void
    {
        $client = $this->hits($this->supervisor, 'ama owusu')->items->first();

        foreach (['email', 'administrative_notes', 'search_text', 'address_line1', 'referral_source'] as $column) {
            $this->assertArrayNotHasKey($column, $client->getAttributes(), "{$column} is not loaded");
        }
        foreach (ClientDirectory::COLUMNS as $column) {
            $this->assertArrayHasKey($column, $client->getAttributes());
        }
        $this->assertMatchesRegularExpression('/^[A-Z]+-0*1$/', $client->formattedNumber());
        $this->assertSame('Ama Owusu', $client->displayName());
    }

    #[Test]
    public function a_search_costs_a_fixed_number_of_queries(): void
    {
        [, $statements] = $this->actAs($this->dr1, $this->created->organization, fn () => $this->recordingQueries(
            fn () => app(ClientSearch::class)->find($this->dr1, 'ama'),
        ));

        // permissions of the membership + the clients: independent of how many clients match
        $this->assertLessThanOrEqual(2, count($statements), implode("\n", $statements));
    }

    #[Test]
    public function words_too_short_for_a_trigram_stay_plain_filters_while_the_rest_use_the_index_shape(): void
    {
        $sqlFor = function (string $term): string {
            [, $statements] = $this->actAs($this->supervisor, $this->created->organization, fn () => $this->recordingQueries(
                fn () => app(ClientSearch::class)->find($this->supervisor, $term),
            ));

            return collect($statements)->first(fn (string $sql) => str_contains($sql, 'search_text'));
        };

        $mixed = $sqlFor('ama o');
        $this->assertSame(1, substr_count($mixed, '(clients.search_text ILIKE ?) IS TRUE'), 'the one-letter word is a plain filter');
        $this->assertSame(2, substr_count($mixed, 'clients.search_text ILIKE ?'), 'both words are still ILIKE conditions');
        $this->assertStringContainsString('clients.search_text ILIKE ? and (clients.search_text ILIKE ?) IS TRUE', $mixed, 'the long word is the bare, indexable form');

        $this->assertStringNotContainsString('IS TRUE', $sqlFor('ama owusu'), 'words of three or more letters keep the bare, indexable form');
        $this->assertStringContainsString('IS TRUE', $sqlFor('a_b'), 'punctuation does not count towards the three');
        $this->assertStringNotContainsString('IS TRUE', $sqlFor('+233 24 410'), 'digits count');

        // and the results are the same as before the rewrite
        $this->assertSame([4, 1], $this->find($this->supervisor, 'ama o'), '"ama" and an "o" somewhere: Ama Boateng and Ama Owusu');
        $this->assertSame([4, 1], $this->find($this->supervisor, 'o ama'));
    }

    #[Test]
    public function the_search_scope_can_be_added_to_any_client_query(): void
    {
        $ids = $this->inTenant($this->created->organization, fn () => ClientSearch::apply(Client::query()->where('status', 'active'), 'boateng')->orderBy('client_number')->pluck('client_number')->all());

        $this->assertSame([4, 5], $ids, 'composes with other conditions: Archie (archived) is excluded');
        $this->assertCount(14, $this->inTenant($this->created->organization, fn () => ClientSearch::apply(Client::query(), '   ')->get()), 'an empty term does not narrow the query');
    }
}
