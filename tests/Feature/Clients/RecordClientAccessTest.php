<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\RecordClientAccess;
use App\Models\Client;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class RecordClientAccessTest extends ClientsTestCase
{
    private $created;

    private OrganizationMembership $dr1;

    private OrganizationMembership $dr2;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();
        $org = $this->created->organization;

        $this->dr1 = $this->addStaff($org, 'clinician');
        $this->dr2 = $this->addStaff($org, 'clinician');
        $this->client = $this->clientIn($org, ['first_name' => 'Ama', 'last_name' => 'Owusu', 'email' => 'ama@example.org']);
    }

    private function open(OrganizationMembership $member, ?Client $client = null, string $tab = 'overview'): bool
    {
        $user = User::query()->findOrFail($member->user_id);

        return $this->actAs($member, $this->created->organization, fn () => app(RecordClientAccess::class)($client ?? $this->client, $user, $tab));
    }

    #[Test]
    public function opening_a_client_records_who_looked_at_which_record(): void
    {
        $this->assertTrue($this->open($this->dr1));

        $entries = $this->auditEntries('client.viewed');
        $this->assertCount(1, $entries);

        $entry = $entries->first();
        $this->assertSame('client', $entry->subject_type);
        $this->assertSame($this->client->id, $entry->subject_id);
        $this->assertSame($this->dr1->user_id, $entry->actor_user_id);
        $this->assertSame($this->dr1->user->name, $entry->actor_label);
        $this->assertSame($this->created->organization->id, $entry->organization_id);
        $this->assertSame('organization', $entry->context);
        $this->assertSame('Opened client record '.$this->client->formattedNumber(), $entry->summary);
        $this->assertSame(['tab' => 'overview'], $entry->metadata);
    }

    #[Test]
    public function the_audit_entry_carries_no_personal_details(): void
    {
        $this->open($this->dr1);

        $serialised = json_encode($this->auditEntries('client.viewed')->first());
        foreach (['Ama', 'Owusu', 'ama@example.org'] as $detail) {
            $this->assertStringNotContainsString($detail, $serialised);
        }
    }

    #[Test]
    public function opening_the_same_client_again_within_ten_minutes_is_not_recorded_again_whatever_the_tab(): void
    {
        $this->assertTrue($this->open($this->dr1, null, 'overview'));
        $this->assertFalse($this->open($this->dr1, null, 'timeline'));
        $this->assertFalse($this->open($this->dr1, null, 'contacts'));
        $this->travel(9)->minutes();
        $this->assertFalse($this->open($this->dr1, null, 'appointments'));

        $this->assertCount(1, $this->auditEntries('client.viewed'));
    }

    #[Test]
    public function after_the_window_the_next_opening_is_recorded(): void
    {
        $this->assertTrue($this->open($this->dr1));

        $this->travel(11)->minutes();

        $this->assertTrue($this->open($this->dr1, null, 'timeline'));
        $this->assertFalse($this->open($this->dr1), 'and a new window starts');

        $entries = $this->auditEntries('client.viewed');
        $this->assertCount(2, $entries);
        $this->assertSame(['overview', 'timeline'], $entries->map(fn ($e) => $e->metadata['tab'])->all());
    }

    #[Test]
    public function the_window_is_per_user_and_per_client(): void
    {
        $other = $this->clientIn($this->created->organization);

        $this->assertTrue($this->open($this->dr1));
        $this->assertTrue($this->open($this->dr2), 'a different person is recorded');
        $this->assertTrue($this->open($this->dr1, $other), 'a different client is recorded');
        $this->assertFalse($this->open($this->dr1));
        $this->assertFalse($this->open($this->dr2));

        $entries = $this->auditEntries('client.viewed');
        $this->assertCount(3, $entries);
        $this->assertEqualsCanonicalizing(
            [[$this->dr1->user_id, $this->client->id], [$this->dr2->user_id, $this->client->id], [$this->dr1->user_id, $other->id]],
            $entries->map(fn ($e) => [$e->actor_user_id, $e->subject_id])->all(),
        );
    }

    #[Test]
    public function the_deduplication_key_is_the_user_and_the_client(): void
    {
        $this->open($this->dr1);

        $this->assertTrue(Cache::has('client-viewed:'.$this->dr1->user_id.':'.$this->client->id));
        $this->assertFalse(Cache::has('client-viewed:'.$this->dr2->user_id.':'.$this->client->id));
    }

    #[Test]
    public function demo_records_are_marked_as_such_in_the_trail(): void
    {
        $demo = $this->demoClientIn($this->created->organization);

        $this->open($this->dr1, $demo, 'timeline');

        $this->assertSame(['tab' => 'timeline', 'environment' => 'demo'], $this->auditEntries('client.viewed')->first()->metadata);
    }

    #[Test]
    public function a_cache_outage_means_more_audit_entries_never_fewer(): void
    {
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache is down'));

        $this->assertTrue($this->open($this->dr1));
        $this->assertTrue($this->open($this->dr1), 'nothing can say it was already recorded, so it is recorded again');

        $this->assertCount(2, $this->auditEntries('client.viewed'));
    }

    #[Test]
    public function a_failure_to_audit_fails_the_call_and_does_not_silence_the_next_attempt(): void
    {
        // Make the audit insert fail.
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT test_refuse_client_viewed CHECK (action <> 'client.viewed')");

        try {
            DB::transaction(fn () => $this->open($this->dr1));   // a savepoint keeps the test transaction usable
            $this->fail('The audit failure must propagate: opening a record without a trail is not allowed.');
        } catch (QueryException) {
            // expected
        } finally {
            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT test_refuse_client_viewed');
        }

        $this->assertFalse(Cache::has('client-viewed:'.$this->dr1->user_id.':'.$this->client->id), 'the key was released');
        $this->assertCount(0, $this->auditEntries('client.viewed'));

        // The very next opening records, instead of being treated as already recorded.
        $this->assertTrue($this->open($this->dr1));
        $this->assertCount(1, $this->auditEntries('client.viewed'));
    }
}
