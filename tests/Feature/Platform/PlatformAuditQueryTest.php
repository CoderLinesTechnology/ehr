<?php

namespace Tests\Feature\Platform;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Platform\Queries\PlatformAuditEntry;
use App\Domain\Platform\Queries\PlatformAuditQuery;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class PlatformAuditQueryTest extends PlatformTestCase
{
    private function entries(array $filters = [], int $perPage = 50, ?int $page = 1)
    {
        return app(PlatformAuditQuery::class)->paginate($filters, $perPage, $page);
    }

    /** @return list<string> */
    private function actions(array $filters = [], int $perPage = 50): array
    {
        return array_map(fn (PlatformAuditEntry $e) => $e->action, $this->entries($filters, $perPage)->items());
    }

    private function record(string $action, AuditContext $context = AuditContext::Platform, ?CarbonImmutable $at = null, array $extra = []): AuditLog
    {
        if ($at !== null) {
            $this->travelTo($at);
        }

        return app(AuditLogger::class)->record($action, context: $context, summary: $extra['summary'] ?? null, before: $extra['before'] ?? null, after: $extra['after'] ?? null, metadata: $extra['metadata'] ?? [], organizationId: $extra['organization_id'] ?? null);
    }

    #[Test]
    public function only_platform_and_system_entries_can_ever_be_listed(): void
    {
        $this->record('platform.thing');
        $this->record('auth.login', AuditContext::System);
        $this->record('appointment.cancelled', AuditContext::Organization, extra: ['summary' => 'Cancelled for a client']);
        $this->record('portal.signed_in', AuditContext::Portal);
        $this->record('public.inquiry', AuditContext::Public);

        $this->assertEqualsCanonicalizing(['platform.thing', 'auth.login'], $this->actions());
        $this->assertEqualsCanonicalizing(['platform.thing'], $this->actions(['context' => 'platform']));
        $this->assertEqualsCanonicalizing(['auth.login'], $this->actions(['context' => 'system']));

        // No filter value can widen it.
        foreach (['organization', 'portal', 'public', '', 'platform OR 1=1', ['organization']] as $context) {
            $this->assertEqualsCanonicalizing(['platform.thing', 'auth.login'], $this->actions(['context' => $context]), 'An unknown context filters nothing; it never widens the log.');
        }
        $this->assertStringNotContainsString('Cancelled for a client', json_encode($this->entries()->items()));
    }

    #[Test]
    public function entries_come_newest_first_and_carry_what_the_log_shows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00:00'));
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(['name' => 'Named Practice'])->organization;
        $this->record('platform.first', at: CarbonImmutable::parse('2026-10-01 09:00:00'));
        $this->record('platform.second', at: CarbonImmutable::parse('2026-10-01 10:00:00'), extra: [
            'summary' => 'Something changed', 'before' => ['status' => 'active'], 'after' => ['status' => 'suspended'],
            'metadata' => ['reason' => 'Unpaid'], 'organization_id' => $organization->id,
        ]);

        $entries = $this->entries()->items();

        $second = $entries[0];
        $this->assertSame('platform.second', $second->action);
        $this->assertSame('platform', $second->context);
        $this->assertSame($admin->name, $second->actorLabel);
        $this->assertSame('Something changed', $second->summary);
        $this->assertSame('Named Practice', $second->organizationName, 'The organization is named, not just identified.');
        $this->assertSame($organization->slug, $second->organizationSlug);
        $this->assertSame(['status' => 'active'], $second->before);
        $this->assertSame(['status' => 'suspended'], $second->after);
        $this->assertSame(['reason' => 'Unpaid'], $second->metadata);
        $this->assertSame('2026-10-01 10:00:00', $second->occurredAt->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($entries[1]->organizationName);
        $this->assertSame('platform.first', $entries[1]->action);
    }

    #[Test]
    public function the_action_filter_matches_the_start_of_the_action_and_wildcards_are_literal(): void
    {
        foreach (['subscription.plan_changed', 'subscription.status_changed', 'organization.created', 'platform.setting_changed', 'a%b.thing'] as $action) {
            $this->record($action);
        }

        $this->assertEqualsCanonicalizing(['subscription.plan_changed', 'subscription.status_changed'], $this->actions(['action' => 'subscription.']));
        $this->assertEqualsCanonicalizing(['subscription.plan_changed'], $this->actions(['action' => 'SUBSCRIPTION.PLAN']), 'Any case.');
        $this->assertSame([], $this->actions(['action' => 'plan_changed']), 'A prefix, not "contains".');
        $this->assertSame([], $this->actions(['action' => '%']), 'A lone percent sign is a character, not "everything".');
        $this->assertSame(['a%b.thing'], $this->actions(['action' => 'a%b']));
        $this->assertCount(5, $this->actions(['action' => '']));
    }

    #[Test]
    public function the_actor_filter_takes_an_address_exactly_or_the_start_of_one(): void
    {
        $alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.org']);
        $alina = User::factory()->create(['name' => 'Alina', 'email' => 'alina@example.org']);
        $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.org']);
        foreach ([[$alice, 'by.alice'], [$alina, 'by.alina'], [$bob, 'by.bob']] as [$user, $action]) {
            $this->actingAs($user);
            $this->app->forgetScopedInstances();
            $this->record($action);
        }

        $this->assertSame(['by.alice'], $this->actions(['actor' => 'alice@example.org']), 'A full address matches exactly.');
        $this->assertSame(['by.alice'], $this->actions(['actor' => 'ALICE@EXAMPLE.ORG']));
        $this->assertSame([], $this->actions(['actor' => 'alic@example.org']), 'A partial address with an @ is not a prefix match.');
        $this->assertEqualsCanonicalizing(['by.alice', 'by.alina'], $this->actions(['actor' => 'ali']), 'Without an @ it is the start of an address.');
        $this->assertSame([], $this->actions(['actor' => 'nobody@example.org']), 'An unknown actor lists nothing.');
        $this->assertSame([], $this->actions(['actor' => '%']), 'A lone percent sign is a character.');
    }

    #[Test]
    public function an_actors_organization_activity_is_not_listed_even_when_the_actor_is_searched_for(): void
    {
        $user = User::factory()->create(['email' => 'both@example.org']);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();
        $this->record('platform.did_this');
        $this->record('client.viewed', AuditContext::Organization, extra: ['summary' => 'Chart opened']);

        $this->assertSame(['platform.did_this'], $this->actions(['actor' => 'both@example.org']));
    }

    #[Test]
    public function the_date_range_includes_the_start_and_excludes_the_end_instant_and_works_in_any_timezone(): void
    {
        $this->record('day.one', at: CarbonImmutable::parse('2026-10-01 23:59:59'));
        $this->record('day.two.start', at: CarbonImmutable::parse('2026-10-02 00:00:00'));
        $this->record('day.two.end', at: CarbonImmutable::parse('2026-10-02 23:59:59'));
        $this->record('day.three', at: CarbonImmutable::parse('2026-10-03 00:00:00'));

        $from = CarbonImmutable::parse('2026-10-02 00:00:00', 'UTC');
        $before = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');

        $this->assertEqualsCanonicalizing(['day.two.start', 'day.two.end'], $this->actions(['from' => $from, 'before' => $before]), 'From is inclusive, before is exclusive.');
        $this->assertEqualsCanonicalizing(['day.two.end', 'day.three'], $this->actions(['from' => CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC')]));
        $this->assertEqualsCanonicalizing(['day.one'], $this->actions(['before' => $from]));

        // The same window expressed in another zone is the same window of instants.
        $newYork = CarbonImmutable::parse('2026-10-01 20:00:00', 'America/New_York'); // = 2026-10-02 00:00:00 UTC
        $this->assertEqualsCanonicalizing(['day.two.start', 'day.two.end', 'day.three'], $this->actions(['from' => $newYork]));
        $this->assertCount(4, $this->actions(['from' => 'not a date', 'before' => '2026-10-03']), 'Only instants are accepted; anything else filters nothing.');
    }

    #[Test]
    public function the_log_is_paged_without_ever_being_counted(): void
    {
        $this->record('seed', at: CarbonImmutable::parse('2026-10-02 11:00:00'));
        $now = CarbonImmutable::parse('2026-10-02 12:00:00');
        $rows = [];
        foreach (range(1, 55) as $i) {
            $rows[] = [
                'id' => (string) Str::uuid7(), 'occurred_at' => $now->addSeconds($i), 'context' => 'platform', 'action' => sprintf('bulk.%02d', $i),
            ];
        }
        DB::table('audit_logs')->insert($rows);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $first = $this->entries();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertCount(50, $first->items());
        $this->assertTrue($first->hasMorePages());
        $this->assertSame('bulk.55', $first->items()[0]->action, 'Newest first.');
        $this->assertFalse($queries->contains(fn ($q) => str_contains($q, 'count(')), 'A log that only grows is never counted.');

        $second = $this->entries(page: 2);
        $this->assertCount(6, $second->items());
        $this->assertFalse($second->hasMorePages());
        $this->assertSame('seed', $second->items()[5]->action, 'The oldest entry is last.');
        $this->assertSame(10, count($this->entries(perPage: 10)->items()));
        $this->assertSame(100, $this->entries(perPage: 5000)->perPage(), 'The page size is capped.');
    }

    #[Test]
    public function a_page_costs_a_fixed_number_of_queries_whatever_the_number_of_organizations_it_names(): void
    {
        $this->signInAsPlatform();
        $organizations = [];
        foreach (range(1, 8) as $i) {
            $organizations[] = $this->createOrganization()->organization;
        }
        $one = $organizations[0];
        $this->record('plain.entry');
        $this->record('one.org', extra: ['organization_id' => $one->id]);
        $this->entries();
        [, $few] = $this->countQueries(fn () => $this->entries());

        foreach ($organizations as $organization) {
            $this->record('many.orgs', extra: ['organization_id' => $organization->id]);
        }
        [$page, $many] = $this->countQueries(fn () => $this->entries());

        $this->assertGreaterThan(8, count($page->items()));
        $this->assertSame($few, $many);
        $this->assertSame(2, $many, 'Entries, then the page\'s organizations in one lookup.');
        $this->assertInstanceOf(Organization::class, $one);
    }

    #[Test]
    public function a_page_with_no_organizations_does_not_look_any_up(): void
    {
        $this->record('plain.entry');

        [, $count] = $this->countQueries(fn () => $this->entries());

        $this->assertSame(1, $count);
    }
}
