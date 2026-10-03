<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\ClientStatus;
use App\Domain\Clients\UpdateClient;
use App\Domain\Platform\CreatedOrganization;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\RecordEnvironment;
use App\Models\Client;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class UpdateClientTest extends ClientsTestCase
{
    private CreatedOrganization $created;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();
        $this->client = $this->clientIn($this->created->organization, [
            'first_name' => 'Ama',
            'last_name' => 'Owusu',
            'email' => 'ama@example.org',
            'phone' => '+233244100001',
            'date_of_birth' => '1990-03-12',
            'administrative_notes' => 'Prefers mornings.',
        ]);
    }

    /** @param array<string, mixed> $input */
    private function update(array $input, ?Client $client = null, ?CreatedOrganization $in = null): Client
    {
        $in ??= $this->created;
        $actor = User::query()->findOrFail($in->ownerMembership->user_id);

        return $this->actAs($in->ownerMembership, $in->organization, fn () => app(UpdateClient::class)($client ?? $this->client, $input, $actor));
    }

    private function stored(?Client $client = null): object
    {
        return DB::table('clients')->where('id', ($client ?? $this->client)->id)->first();
    }

    #[Test]
    public function it_changes_the_details_and_hands_back_the_callers_record_in_sync(): void
    {
        $returned = $this->update(['first_name' => 'Adwoa', 'city' => 'Kumasi', 'phone' => '024 410 0009']);

        $this->assertSame($this->client, $returned);
        $this->assertSame('Adwoa', $this->client->first_name, 'the instance the caller holds is current');
        $this->assertSame('+233244100009', $this->client->phone);

        $row = $this->stored();
        $this->assertSame(['Adwoa', 'Owusu', 'Kumasi', '+233244100009'], [$row->first_name, $row->last_name, $row->city, $row->phone]);
    }

    #[Test]
    public function it_audits_exactly_what_changed(): void
    {
        $this->update(['first_name' => 'Adwoa', 'last_name' => 'Owusu', 'city' => 'Kumasi', 'date_of_birth' => '1990-03-13']);

        $entries = $this->auditEntries('client.updated');
        $this->assertCount(1, $entries);

        $entry = $entries->first();
        $this->assertSame('client', $entry->subject_type);
        $this->assertSame($this->client->id, $entry->subject_id);
        $this->assertSame($this->created->ownerMembership->user_id, $entry->actor_user_id);
        $this->assertSame('Client '.$this->client->formattedNumber().' updated', $entry->summary);

        // last_name was submitted but not changed: it is not in the diff.
        $this->assertEquals(['first_name' => 'Ama', 'city' => null, 'date_of_birth' => '1990-03-12'], $entry->before);
        $this->assertEquals(['first_name' => 'Adwoa', 'city' => 'Kumasi', 'date_of_birth' => '1990-03-13'], $entry->after);
        $this->assertEqualsCanonicalizing(['first_name', 'city', 'date_of_birth'], $entry->metadata['fields']);
    }

    #[Test]
    public function the_free_text_notes_never_enter_the_audit_trail_but_the_change_is_recorded(): void
    {
        $this->update(['administrative_notes' => 'Calls from a neighbour\'s phone.', 'city' => 'Kumasi']);

        $entry = $this->auditEntries('client.updated')->first();
        $this->assertEquals(['city' => null], $entry->before);
        $this->assertEquals(['city' => 'Kumasi'], $entry->after);
        $this->assertContains('administrative_notes', $entry->metadata['fields']);
        $this->assertStringNotContainsString('neighbour', json_encode($entry));
        $this->assertStringNotContainsString('mornings', json_encode($entry));

        $this->assertSame('Calls from a neighbour\'s phone.', $this->stored()->administrative_notes, 'the notes themselves are saved');
    }

    #[Test]
    public function a_notes_only_change_is_still_audited(): void
    {
        $this->update(['administrative_notes' => 'Moved to the afternoon list.']);

        $entries = $this->auditEntries('client.updated');
        $this->assertCount(1, $entries);
        $this->assertNull($entries->first()->before);
        $this->assertNull($entries->first()->after);
        $this->assertSame(['administrative_notes'], $entries->first()->metadata['fields']);
        $this->assertStringNotContainsString('afternoon', json_encode($entries->first()));
    }

    #[Test]
    public function submitting_the_same_values_changes_and_audits_nothing(): void
    {
        $before = $this->stored();

        $this->update([
            'first_name' => 'Ama',
            'last_name' => 'Owusu',
            'email' => 'AMA@Example.org',         // same address, different case
            'phone' => '+233 24 410 0001',        // same number, typed differently
            'date_of_birth' => '1990-03-12',
            'administrative_notes' => 'Prefers mornings.',
        ]);

        $this->assertCount(0, $this->auditEntries('client.updated'));
        $this->assertEquals($before, $this->stored(), 'not even updated_at moves');
    }

    #[Test]
    public function the_unchanged_remainder_is_left_alone_when_only_some_fields_are_submitted(): void
    {
        $this->update(['city' => 'Tema']);

        $row = $this->stored();
        $this->assertSame(['Ama', 'ama@example.org', '+233244100001', '1990-03-12', 'Tema'], [$row->first_name, $row->email, $row->phone, $row->date_of_birth, $row->city]);
    }

    #[Test]
    public function an_update_may_clear_contact_details_because_the_requirement_is_for_new_clients(): void
    {
        $this->update(['email' => null, 'phone' => null]);

        $row = $this->stored();
        $this->assertNull($row->email);
        $this->assertNull($row->phone);
    }

    #[Test]
    public function status_number_environment_and_organization_cannot_be_changed_through_the_input(): void
    {
        $other = $this->createOrganization();

        $this->update([
            'city' => 'Tema',
            'status' => 'archived',
            'client_number' => 999,
            'record_environment' => 'demo',
            'organization_id' => $other->organization->id,
            'archived_at' => now()->toDateTimeString(),
        ]);

        $row = $this->stored();
        $this->assertSame('active', $row->status);
        $this->assertSame(1, $row->client_number);
        $this->assertSame('live', $row->record_environment);
        $this->assertSame($this->created->organization->id, $row->organization_id);
        $this->assertNull($row->archived_at);
        $this->assertSame('Tema', $row->city);
    }

    #[Test]
    public function invalid_values_are_refused_and_nothing_is_saved(): void
    {
        foreach ([
            ['phone', ['phone' => 'call me']],
            ['email', ['email' => 'nope']],
            ['date_of_birth', ['date_of_birth' => '2999-01-01']],
            ['sex', ['sex' => 'robot']],
        ] as [$field, $input]) {
            try {
                $this->update($input + ['city' => 'Nowhere']);
                $this->fail("[{$field}] should have been refused");
            } catch (DomainException $e) {
                $this->assertSame($field, $e->field());
            }
        }

        $this->assertNull($this->stored()->city, 'a refused update saves none of its fields');
        $this->assertCount(0, $this->auditEntries('client.updated'));
    }

    #[Test]
    public function the_primary_clinician_can_be_changed_to_an_active_provider_only(): void
    {
        $org = $this->created->organization;
        $provider = $this->addStaff($org, 'clinician');
        $notProvider = $this->addStaff($org, 'receptionist', ['is_provider' => false]);
        $elsewhere = $this->addStaff($this->createOrganization()->organization, 'clinician');

        $this->update(['primary_clinician_membership_id' => $provider->id]);
        $this->assertSame($provider->id, $this->stored()->primary_clinician_membership_id);

        foreach ([$notProvider->id, $elsewhere->id] as $bad) {
            try {
                $this->update(['primary_clinician_membership_id' => $bad]);
                $this->fail('should have been refused');
            } catch (DomainException $e) {
                $this->assertSame('invalid_clinician', $e->errorCode());
            }
        }

        $this->update(['primary_clinician_membership_id' => null]);
        $this->assertNull($this->stored()->primary_clinician_membership_id, 'unassigning is allowed');
    }

    #[Test]
    public function editing_a_client_whose_clinician_has_left_does_not_fail_but_choosing_another_inactive_one_does(): void
    {
        $org = $this->created->organization;
        $left = $this->addStaff($org, 'clinician');
        $alsoLeft = $this->addStaff($org, 'clinician');
        $this->update(['primary_clinician_membership_id' => $left->id]);

        $this->inTenant($org, fn () => OrganizationMembership::query()->whereKey([$left->id, $alsoLeft->id])->get()
            ->each(fn ($m) => $m->forceFill(['status' => 'deactivated'])->save()));

        // the unchanged assignment is accepted
        $this->update(['primary_clinician_membership_id' => $left->id, 'city' => 'Tema']);
        $this->assertSame($left->id, $this->stored()->primary_clinician_membership_id);
        $this->assertSame('Tema', $this->stored()->city);

        // a new assignment to somebody who has left is not
        $this->expectException(DomainException::class);
        $this->update(['primary_clinician_membership_id' => $alsoLeft->id]);
    }

    #[Test]
    public function the_primary_location_must_be_an_active_location_unless_unchanged(): void
    {
        $org = $this->created->organization;
        $open = $this->inTenant($org, fn () => Location::factory()->create());
        $closing = $this->inTenant($org, fn () => Location::factory()->create());
        $elsewhere = $this->inTenant($this->createOrganization()->organization, fn () => Location::factory()->create());

        $this->update(['primary_location_id' => $closing->id]);
        $this->inTenant($org, fn () => Location::query()->whereKey($closing->id)->update(['is_active' => false]));

        $this->update(['primary_location_id' => $closing->id, 'city' => 'Tema']);   // unchanged: fine
        $this->assertSame('Tema', $this->stored()->city);

        $this->update(['primary_location_id' => $open->id]);                           // an active one: fine
        $this->assertSame($open->id, $this->stored()->primary_location_id);

        foreach ([$closing->id, $elsewhere->id] as $bad) {
            try {
                $this->update(['primary_location_id' => $bad]);
                $this->fail('should have been refused');
            } catch (DomainException $e) {
                $this->assertSame('invalid_location', $e->errorCode());
            }
        }
    }

    #[Test]
    public function a_client_of_another_organization_cannot_be_updated_from_this_one(): void
    {
        $other = $this->createOrganization();
        $foreign = $this->clientIn($other->organization, ['first_name' => 'Yaw', 'city' => null]);

        try {
            $this->update(['first_name' => 'Hijacked', 'city' => 'Nowhere'], $foreign);
            $this->fail('A client of another organization must not be found.');
        } catch (ModelNotFoundException) {
            // expected: the tenant scope hides it
        }

        $row = $this->stored($foreign);
        $this->assertSame(['Yaw', null], [$row->first_name, $row->city]);
        $this->assertCount(0, $this->auditEntries('client.updated'));
    }

    #[Test]
    public function demo_and_live_clients_are_edited_the_same_way_and_stay_what_they_are(): void
    {
        $demo = $this->demoClientIn($this->created->organization);

        $this->update(['city' => 'Tema'], $demo);

        $row = $this->stored($demo);
        $this->assertSame('Tema', $row->city);
        $this->assertSame(RecordEnvironment::Demo->value, $row->record_environment);
        $this->assertSame(ClientStatus::Active->value, $row->status);
    }
}
