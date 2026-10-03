<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\ClientTimelineReader;
use App\Domain\Clients\Timeline;
use App\Domain\Tenancy\TenantMismatch;
use App\Models\Client;
use App\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;

class ClientTimelineReaderTest extends ClientsTestCase
{
    private $created;

    private OrganizationMembership $dr1;

    private OrganizationMembership $supervisor;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();
        $org = $this->created->organization;

        $this->dr1 = $this->addStaff($org, 'clinician');
        $this->supervisor = $this->addStaff($org, 'supervisor');
        $this->client = $this->clientIn($org, ['primary_clinician_membership_id' => $this->dr1->id]);
    }

    private function record(Client $client, string $category, string $summary, ?string $at = null, ?string $actor = null): void
    {
        $this->inTenant($this->created->organization, fn () => app(Timeline::class)->record(
            $client,
            $category,
            'test.event',
            $summary,
            actorUserId: $actor,
            occurredAt: $at !== null ? CarbonImmutable::parse($at, 'UTC') : null,
        ));
    }

    private function page(OrganizationMembership $viewer, ?string $cursor = null, ?Client $client = null, ?ClientTimelineReader $reader = null)
    {
        return $this->actAs($viewer, $this->created->organization, fn () => ($reader ?? app(ClientTimelineReader::class))->page($client ?? $this->client, $viewer, $cursor));
    }

    /** @return list<string> summaries, newest first, following the cursors to the end */
    private function everything(OrganizationMembership $viewer, ?Client $client = null): array
    {
        $summaries = [];
        $cursor = null;

        do {
            $page = $this->page($viewer, $cursor, $client);
            array_push($summaries, ...$page->entries->pluck('summary')->all());
            $cursor = $page->next;
        } while ($cursor !== null);

        return $summaries;
    }

    // ── categories ──────────────────────────────────────────────────────

    #[Test]
    public function administrative_and_scheduling_entries_are_shown_and_every_other_category_is_hidden_from_everyone(): void
    {
        foreach (['administrative', 'scheduling', 'clinical', 'financial', 'communication', 'program', 'document'] as $i => $category) {
            $this->record($this->client, $category, "A {$category} entry", '2026-05-01 10:0'.$i.':00');
        }

        foreach ([$this->dr1, $this->supervisor, $this->created->ownerMembership] as $viewer) {
            $summaries = $this->page($viewer)->entries->pluck('summary')->all();

            $this->assertSame(['A scheduling entry', 'A administrative entry'], $summaries, 'even the organization administrator: those permissions do not exist yet');
        }
    }

    #[Test]
    public function the_visible_categories_are_listed_by_a_method_too(): void
    {
        $reader = app(ClientTimelineReader::class);

        $this->assertSame(['administrative', 'scheduling'], $reader->visibleCategories($this->dr1));
    }

    #[Test]
    public function a_category_is_unlocked_by_the_permission_registered_for_it(): void
    {
        $this->record($this->client, 'administrative', 'Registered', '2026-05-01 10:00:00');
        $this->record($this->client, 'clinical', 'Clinical note', '2026-05-01 10:01:00');
        $this->record($this->client, 'financial', 'Invoice', '2026-05-01 10:02:00');
        $this->record($this->client, 'program', 'Enrolled', '2026-05-01 10:03:00');

        // The extension point: a category maps to a permission key. 'reports.view' stands in for a future
        // clinical permission; an unknown key and a null both keep a category hidden from everybody.
        $reader = new ClientTimelineReader(app(\App\Domain\Identity\PermissionResolver::class), [
            Timeline::CLINICAL => 'reports.view',
            Timeline::FINANCIAL => 'billing.nonexistent',
            Timeline::PROGRAM => null,
        ]);

        $this->assertSame(['Clinical note', 'Registered'], $this->page($this->supervisor, null, null, $reader)->entries->pluck('summary')->all(), 'a holder of the permission');
        $this->assertSame(['Registered'], $this->page($this->dr1, null, null, $reader)->entries->pluck('summary')->all(), 'a clinician without it');
    }

    #[Test]
    public function the_default_gating_table_keeps_the_future_categories_closed(): void
    {
        $this->assertSame([Timeline::ADMINISTRATIVE, Timeline::SCHEDULING], ClientTimelineReader::OPEN_CATEGORIES);
        $this->assertSame(
            [Timeline::CLINICAL, Timeline::FINANCIAL, Timeline::PROGRAM, Timeline::DOCUMENT, Timeline::COMMUNICATION],
            array_keys(ClientTimelineReader::GATED_CATEGORIES),
        );
        $this->assertSame([null], array_values(array_unique(ClientTimelineReader::GATED_CATEGORIES)), 'no permission exists for them yet');
    }

    #[Test]
    public function every_category_has_an_icon_and_a_label(): void
    {
        foreach (\App\Models\TimelineEntry::CATEGORIES as $category) {
            $presentation = ClientTimelineReader::presentation($category);

            $this->assertNotSame('', $presentation['icon'], $category);
            $this->assertNotSame('', $presentation['label'], $category);
        }
        $this->assertSame('info', ClientTimelineReader::presentation('something-new')['icon']);
    }

    // ── keyset pagination ───────────────────────────────────────────────

    #[Test]
    public function pages_follow_each_other_without_gaps_or_repeats_newest_first(): void
    {
        foreach (range(1, 75) as $i) {
            $this->record($this->client, 'administrative', sprintf('Entry %02d', $i), CarbonImmutable::parse('2026-05-01 08:00:00', 'UTC')->addMinutes($i)->toDateTimeString());
        }

        $first = $this->page($this->dr1);
        $this->assertCount(ClientTimelineReader::PAGE_SIZE, $first->entries);
        $this->assertNotNull($first->next);
        $this->assertSame('Entry 75', $first->entries->first()->summary);
        $this->assertSame('Entry 46', $first->entries->last()->summary);

        $second = $this->page($this->dr1, $first->next);
        $this->assertCount(30, $second->entries);
        $this->assertSame('Entry 45', $second->entries->first()->summary);

        $third = $this->page($this->dr1, $second->next);
        $this->assertCount(15, $third->entries);
        $this->assertNull($third->next, 'the last page has no next cursor');
        $this->assertSame('Entry 01', $third->entries->last()->summary);

        $all = $this->everything($this->dr1);
        $this->assertCount(75, $all);
        $this->assertSame($all, array_values(array_unique($all)));
        $expected = array_map(fn ($i) => sprintf('Entry %02d', $i), range(75, 1));
        $this->assertSame($expected, $all);
    }

    #[Test]
    public function entries_with_the_same_instant_are_split_across_pages_exactly_once(): void
    {
        // 45 entries at one instant: the page boundary falls inside the tie.
        foreach (range(1, 45) as $i) {
            $this->record($this->client, 'administrative', sprintf('Tie %02d', $i), '2026-05-01 12:00:00');
        }
        $this->record($this->client, 'administrative', 'Older', '2026-05-01 11:00:00');
        $this->record($this->client, 'administrative', 'Newer', '2026-05-01 13:00:00');

        $all = $this->everything($this->dr1);

        $this->assertCount(47, $all);
        $this->assertSame(47, count(array_unique($all)), 'no entry is shown twice');
        $this->assertSame('Newer', $all[0]);
        $this->assertSame('Older', $all[46]);
        $this->assertEqualsCanonicalizing(array_map(fn ($i) => sprintf('Tie %02d', $i), range(1, 45)), array_slice($all, 1, 45));
    }

    #[Test]
    public function microseconds_are_part_of_the_position(): void
    {
        $base = CarbonImmutable::parse('2026-05-01 12:00:00', 'UTC');
        foreach (range(1, 40) as $i) {
            $this->inTenant($this->created->organization, fn () => app(Timeline::class)->record(
                $this->client, 'administrative', 'test.event', sprintf('Micro %02d', $i), occurredAt: $base->addMicroseconds($i * 7),
            ));
        }

        $all = $this->everything($this->dr1);

        $this->assertSame(array_map(fn ($i) => sprintf('Micro %02d', $i), range(40, 1)), $all);
    }

    #[Test]
    public function entries_written_while_paging_do_not_shift_the_next_page(): void
    {
        foreach (range(1, 40) as $i) {
            $this->record($this->client, 'administrative', sprintf('Entry %02d', $i), CarbonImmutable::parse('2026-05-01 08:00:00', 'UTC')->addMinutes($i)->toDateTimeString());
        }

        $first = $this->page($this->dr1);
        $secondBefore = $this->page($this->dr1, $first->next)->entries->pluck('summary')->all();

        // five new entries arrive at the top
        foreach (range(1, 5) as $i) {
            $this->record($this->client, 'administrative', "Fresh {$i}", '2026-06-01 09:00:00');
        }

        $secondAfter = $this->page($this->dr1, $first->next)->entries->pluck('summary')->all();

        $this->assertSame($secondBefore, $secondAfter, 'an offset would have pushed five entries onto the next page');
    }

    #[Test]
    public function an_unreadable_cursor_starts_from_the_newest(): void
    {
        $this->record($this->client, 'administrative', 'Only entry', '2026-05-01 10:00:00');

        $olderCursor = rtrim(strtr(base64_encode(json_encode(['2026-05-01T09:59:59.000000+00:00', (string) \Illuminate\Support\Str::uuid7()])), '+/', '-_'), '=');
        $newerCursor = rtrim(strtr(base64_encode(json_encode(['2026-05-01T10:00:01.000000+00:00', (string) \Illuminate\Support\Str::uuid7()])), '+/', '-_'), '=');
        $bad = [
            '', 'garbage', '!!!!', str_repeat('A', 500),
            rtrim(strtr(base64_encode('not json'), '+/', '-_'), '='),
            rtrim(strtr(base64_encode('{"a":1}'), '+/', '-_'), '='),
            rtrim(strtr(base64_encode(json_encode(['2026-05-01T10:00:00.000000+00:00'])), '+/', '-_'), '='),
            rtrim(strtr(base64_encode(json_encode(['yesterday', (string) \Illuminate\Support\Str::uuid7()])), '+/', '-_'), '='),
            rtrim(strtr(base64_encode(json_encode(['2026-05-01T10:00:00.000000+00:00', 'not-a-uuid'])), '+/', '-_'), '='),
            rtrim(strtr(base64_encode(json_encode(['2026-05-01T10:00:00.000000+00:00', "'; DROP TABLE clients; --"])), '+/', '-_'), '='),
            rtrim(strtr(base64_encode(json_encode([['nested'], 1])), '+/', '-_'), '='),
        ];

        foreach ($bad as $cursor) {
            $this->assertSame(['Only entry'], $this->page($this->dr1, $cursor)->entries->pluck('summary')->all(), 'cursor: '.substr($cursor, 0, 30));
        }

        // well-formed cursors are positions: entries older than the position are shown, the rest are not
        $this->assertSame(['Only entry'], $this->page($this->dr1, $newerCursor)->entries->pluck('summary')->all());
        $this->assertSame([], $this->page($this->dr1, $olderCursor)->entries->pluck('summary')->all());
    }

    // ── isolation ───────────────────────────────────────────────────────

    #[Test]
    public function other_clients_and_other_organizations_never_appear(): void
    {
        $otherClient = $this->clientIn($this->created->organization);
        $foreign = $this->createOrganization();
        $foreignClient = $this->clientIn($foreign->organization);

        $this->record($this->client, 'administrative', 'Mine', '2026-05-01 10:00:00');
        $this->record($otherClient, 'administrative', 'Someone else in my organization', '2026-05-01 10:01:00');
        $this->inTenant($foreign->organization, fn () => app(Timeline::class)->record($foreignClient, 'administrative', 'test.event', 'Another organization', occurredAt: CarbonImmutable::parse('2026-05-01 10:02:00', 'UTC')));

        $this->assertSame(['Mine'], $this->everything($this->dr1));
        $this->assertSame(['Someone else in my organization'], $this->everything($this->created->ownerMembership, $otherClient));

        // the other organization's client cannot even be asked for through our tenant
        try {
            $this->page($this->dr1, null, $foreignClient);
            $this->fail('A client of another organization must be refused.');
        } catch (TenantMismatch) {
            // expected
        }
    }

    #[Test]
    public function a_reader_who_may_not_see_the_client_gets_a_not_found_whatever_the_caller_checked(): void
    {
        $someoneElses = $this->clientIn($this->created->organization);   // not dr1's
        $this->record($someoneElses, 'administrative', 'Private to the other clinician', '2026-05-01 10:00:00');

        try {
            $this->page($this->dr1, null, $someoneElses);
            $this->fail('A clinician must not read the timeline of a client they cannot see.');
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            $this->assertSame(404, $e->status(), 'not found: existence is not revealed');
        }

        // an appointment is a reason to see them
        $this->bookAppointment($this->created->organization, $someoneElses, $this->dr1);
        $this->assertSame(['Private to the other clinician'], $this->page($this->dr1, null, $someoneElses)->entries->pluck('summary')->all());
    }

    #[Test]
    public function a_member_who_is_no_longer_active_reads_nothing(): void
    {
        $this->record($this->client, 'administrative', 'Registered', '2026-05-01 10:00:00');
        $this->inTenant($this->created->organization, fn () => OrganizationMembership::query()->whereKey($this->supervisor->id)->first()->forceFill(['status' => 'suspended'])->save());
        $suspended = $this->membershipRecord($this->supervisor->id);

        $this->assertSame([], app(ClientTimelineReader::class)->visibleCategories($suspended));

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->page($suspended);
    }

    #[Test]
    public function a_viewer_from_another_organization_is_refused(): void
    {
        $foreign = $this->createOrganization();

        $this->expectException(TenantMismatch::class);

        $this->inTenant($this->created->organization, fn () => app(ClientTimelineReader::class)->page($this->client, $foreign->ownerMembership));
    }

    // ── cost ────────────────────────────────────────────────────────────

    #[Test]
    public function a_page_costs_two_queries_however_many_entries_or_actors(): void
    {
        foreach (range(1, 20) as $i) {
            $this->record($this->client, 'administrative', "Entry {$i}", '2026-05-01 10:00:00', $i % 2 ? $this->dr1->user_id : $this->supervisor->user_id);
        }

        $this->page($this->dr1);   // the viewer's permissions are looked up once per request: measure the steady state

        [$page, $statements] = $this->actAs($this->dr1, $this->created->organization, fn () => $this->recordingQueries(
            fn () => app(ClientTimelineReader::class)->page($this->client, $this->dr1),
        ));

        $this->assertCount(20, $page->entries);
        $this->assertSame([$this->dr1->user->name, $this->supervisor->user->name], [$page->entries[1]->actor->name, $page->entries[0]->actor->name]);
        $this->assertCount(2, $statements, implode("\n", $statements));   // the entries + their actors
    }

    #[Test]
    public function the_page_query_walks_the_timeline_index_instead_of_sorting(): void
    {
        foreach (range(1, 40) as $i) {
            $this->record($this->client, 'administrative', "Entry {$i}", CarbonImmutable::parse('2026-05-01 08:00:00', 'UTC')->addMinutes($i)->toDateTimeString());
        }
        $cursor = $this->page($this->dr1)->next;

        [, $statements] = $this->actAs($this->dr1, $this->created->organization, fn () => $this->recordingQueries(
            fn () => app(ClientTimelineReader::class)->page($this->client, $this->dr1, $cursor),
        ));

        $sql = collect($statements)->first(fn (string $statement) => str_contains($statement, 'from "timeline_entries"'));
        $this->assertNotNull($sql, implode("\n", $statements));
        $this->assertStringContainsString('order by "occurred_at" desc, "id" desc', $sql);
        $this->assertStringContainsString('(occurred_at, id) < (?::timestamptz, ?::uuid)', $sql, 'a row-value comparison, not OFFSET');
        $this->assertStringContainsString('limit 31', $sql);
        $this->assertStringNotContainsString('offset', $sql);
    }
}
