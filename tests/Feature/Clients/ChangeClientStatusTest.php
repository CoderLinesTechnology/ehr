<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\ChangeClientStatus;
use App\Domain\Clients\ClientStatus;
use App\Domain\Platform\CreatedOrganization;
use App\Domain\Saas\LimitReached;
use App\Domain\Shared\DomainException;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class ChangeClientStatusTest extends ClientsTestCase
{
    private CreatedOrganization $created;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization(plan: 'starter');
    }

    private function change(Client $client, ClientStatus $to, ?string $reason = null, ?CreatedOrganization $in = null): Client
    {
        $in ??= $this->created;
        $actor = User::query()->findOrFail($in->ownerMembership->user_id);

        return $this->actAs($in->ownerMembership, $in->organization, fn () => app(ChangeClientStatus::class)($client, $to, $actor, $reason));
    }

    private function clientWith(ClientStatus $status, array $attributes = []): Client
    {
        return $this->inTenant($this->created->organization, fn () => Client::factory()->status($status)->create($attributes));
    }

    private function row(Client $client): object
    {
        return DB::table('clients')->where('id', $client->id)->first();
    }

    private function timeline(Client $client): \Illuminate\Support\Collection
    {
        return DB::table('timeline_entries')->where('client_id', $client->id)->orderBy('occurred_at')->orderBy('id')->get()
            ->each(fn ($e) => $e->metadata = $e->metadata !== null ? json_decode($e->metadata, true) : null);
    }

    #[Test]
    public function archiving_keeps_the_record_stamps_the_time_and_says_why(): void
    {
        $client = $this->clientWith(ClientStatus::Active);

        $returned = $this->change($client, ClientStatus::Archived, '  Moved abroad.  ');

        $this->assertSame($client, $returned);
        $this->assertSame(ClientStatus::Archived, $client->status, 'the callers record is current');

        $row = $this->row($client);
        $this->assertSame('archived', $row->status);
        $this->assertNotNull($row->archived_at);

        $audit = $this->auditEntries('client.status_changed');
        $this->assertCount(1, $audit);
        $this->assertSame($client->id, $audit[0]->subject_id);
        $this->assertSame($this->created->ownerMembership->user_id, $audit[0]->actor_user_id);
        $this->assertEquals(['status' => 'active'], $audit[0]->before);
        $this->assertEquals(['status' => 'archived'], $audit[0]->after);
        $this->assertSame('Moved abroad.', $audit[0]->metadata['reason']);
        $this->assertSame('Client '.$client->formattedNumber().': Active → Archived', $audit[0]->summary);

        $timeline = $this->timeline($client);
        $this->assertCount(1, $timeline);
        $this->assertSame('administrative', $timeline[0]->category);
        $this->assertSame('client.status_changed', $timeline[0]->type);
        $this->assertSame('Client archived', $timeline[0]->summary);
        $this->assertSame($this->created->ownerMembership->user_id, $timeline[0]->actor_user_id);
        $this->assertEquals(['from' => 'active', 'to' => 'archived', 'reason' => 'Moved abroad.'], $timeline[0]->metadata);
    }

    #[Test]
    public function restoring_clears_the_archive_stamp_and_needs_no_reason(): void
    {
        $client = $this->clientWith(ClientStatus::Active);
        $this->change($client, ClientStatus::Archived, 'Moved abroad.');

        $this->change($client, ClientStatus::Active);

        $row = $this->row($client);
        $this->assertSame('active', $row->status);
        $this->assertNull($row->archived_at);
        $this->assertSame('Client restored', $this->timeline($client)->last()->summary);
        $this->assertCount(2, $this->auditEntries('client.status_changed'));
    }

    #[Test]
    public function a_client_can_be_marked_inactive_and_active_again(): void
    {
        $client = $this->clientWith(ClientStatus::Active);

        $this->change($client, ClientStatus::Inactive, 'On a break.');
        $this->assertSame('inactive', $this->row($client)->status);
        $this->assertNull($this->row($client)->archived_at, 'inactive is not archived');
        $this->assertSame('Client marked inactive', $this->timeline($client)->last()->summary);

        $this->change($client, ClientStatus::Active);
        $this->assertSame('active', $this->row($client)->status);
        $this->assertSame('Client marked active', $this->timeline($client)->last()->summary);
    }

    #[Test]
    public function a_reason_is_required_to_archive_or_deactivate_but_not_to_activate(): void
    {
        $client = $this->clientWith(ClientStatus::Active);

        foreach ([ClientStatus::Archived, ClientStatus::Inactive] as $to) {
            foreach ([null, '', '   '] as $reason) {
                try {
                    $this->change($client, $to, $reason);
                    $this->fail('A reason should be required.');
                } catch (DomainException $e) {
                    $this->assertSame('reason', $e->field());
                    $this->assertSame('reason_required', $e->errorCode());
                }
            }
        }

        $this->assertSame('active', $this->row($client)->status);
        $this->assertCount(0, $this->auditEntries('client.status_changed'), 'a refusal leaves no trace');
        $this->assertCount(0, $this->timeline($client));
    }

    #[Test]
    public function an_archived_client_can_only_be_restored_to_active(): void
    {
        $client = $this->clientWith(ClientStatus::Archived);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cannot be changed to Inactive');

        $this->change($client, ClientStatus::Inactive, 'Because.');
    }

    #[Test]
    public function asking_for_the_status_a_client_already_has_changes_and_records_nothing(): void
    {
        $client = $this->clientWith(ClientStatus::Inactive);

        $this->change($client, ClientStatus::Inactive, 'Again.');
        $this->change($client, ClientStatus::Inactive);

        $this->assertCount(0, $this->auditEntries('client.status_changed'));
        $this->assertCount(0, $this->timeline($client));
    }

    #[Test]
    public function restoring_an_archived_live_client_rechecks_the_active_client_limit(): void
    {
        $this->limitActiveClientsTo($this->created->organization, 1);

        $occupant = $this->clientWith(ClientStatus::Active);
        $archived = $this->clientWith(ClientStatus::Archived, ['archived_at' => now()]);

        try {
            $this->change($archived, ClientStatus::Active);
            $this->fail('The plan is full: the restore must be refused.');
        } catch (LimitReached $e) {
            $this->assertStringContainsString('1 active clients', $e->userMessage());
        }

        $this->assertSame('archived', $this->row($archived)->status, 'still archived');
        $this->assertNotNull($this->row($archived)->archived_at);
        $this->assertCount(0, $this->auditEntries('client.status_changed'));
        $this->assertCount(0, $this->timeline($archived));

        // Freeing a place makes it possible.
        $this->change($occupant, ClientStatus::Inactive, 'On a break.');
        $this->change($archived, ClientStatus::Active);

        $this->assertSame('active', $this->row($archived)->status);
    }

    #[Test]
    public function marking_an_inactive_client_active_is_checked_against_the_limit_too(): void
    {
        $this->limitActiveClientsTo($this->created->organization, 1);

        $this->clientWith(ClientStatus::Active);
        $inactive = $this->clientWith(ClientStatus::Inactive);

        $this->expectException(LimitReached::class);

        $this->change($inactive, ClientStatus::Active);
    }

    #[Test]
    public function archiving_and_deactivating_are_never_blocked_by_the_limit(): void
    {
        $this->limitActiveClientsTo($this->created->organization, 0);

        $a = $this->clientWith(ClientStatus::Active);
        $b = $this->clientWith(ClientStatus::Active);

        $this->change($a, ClientStatus::Archived, 'Moved.');
        $this->change($b, ClientStatus::Inactive, 'Break.');

        $this->assertSame(['archived', 'inactive'], [$this->row($a)->status, $this->row($b)->status]);
    }

    #[Test]
    public function demo_clients_are_restored_whatever_the_limit(): void
    {
        $this->limitActiveClientsTo($this->created->organization, 0);

        $demo = $this->inTenant($this->created->organization, fn () => Client::factory()->demo()->status(ClientStatus::Archived)->create());

        $this->change($demo, ClientStatus::Active);

        $this->assertSame('active', $this->row($demo)->status);
    }

    #[Test]
    public function another_organizations_client_cannot_be_changed_from_here(): void
    {
        $other = $this->createOrganization();
        $foreign = $this->inTenant($other->organization, fn () => Client::factory()->create());

        try {
            $this->change($foreign, ClientStatus::Archived, 'Hijack.');
            $this->fail('A client of another organization must not be found.');
        } catch (ModelNotFoundException) {
            // expected
        }

        $this->assertSame('active', $this->row($foreign)->status);
        $this->assertCount(0, $this->auditEntries('client.status_changed'));
        $this->assertCount(0, $this->timeline($foreign));
    }

    #[Test]
    public function the_clients_module_has_no_way_to_delete_a_client(): void
    {
        // A tripwire: archive, never delete. (Contacts may be removed; clients may not.)
        $files = glob(app_path('Domain/Clients/*.php'));
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression('/\$client->(delete|forceDelete)\(/', $source, basename($file));
            $this->assertDoesNotMatchRegularExpression('/Client::[^;]*->(delete|forceDelete)\(/', $source, basename($file));
            $this->assertDoesNotMatchRegularExpression('/table\(\'clients\'\)[^;]*->delete\(/', $source, basename($file));
        }
    }
}
