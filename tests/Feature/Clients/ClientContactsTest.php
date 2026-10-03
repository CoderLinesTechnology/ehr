<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\DeleteClientContact;
use App\Domain\Clients\SaveClientContact;
use App\Domain\Platform\CreatedOrganization;
use App\Domain\Shared\DomainException;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class ClientContactsTest extends ClientsTestCase
{
    private CreatedOrganization $created;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();
        $this->client = $this->clientIn($this->created->organization);
    }

    /** @param array<string, mixed> $input */
    private function save(array $input, ?ClientContact $contact = null, ?Client $client = null, ?CreatedOrganization $in = null): ClientContact
    {
        $in ??= $this->created;
        $actor = User::query()->findOrFail($in->ownerMembership->user_id);

        return $this->actAs($in->ownerMembership, $in->organization, fn () => app(SaveClientContact::class)($client ?? $this->client, $input, $contact, $actor));
    }

    private function remove(ClientContact $contact, ?Client $client = null, ?CreatedOrganization $in = null): void
    {
        $in ??= $this->created;
        $actor = User::query()->findOrFail($in->ownerMembership->user_id);

        $this->actAs($in->ownerMembership, $in->organization, fn () => app(DeleteClientContact::class)($client ?? $this->client, $contact, $actor));
    }

    private function row(ClientContact $contact): ?object
    {
        return DB::table('client_contacts')->where('id', $contact->id)->first();
    }

    #[Test]
    public function it_adds_a_contact_with_a_normalised_phone_and_email(): void
    {
        $contact = $this->save([
            'name' => '  Kofi Owusu ',
            'relationship' => 'Brother',
            'phone' => '024 410 0002',
            'email' => 'Kofi@Example.org',
            'is_emergency_contact' => '1',
            'notes' => 'Works nights.',
        ]);

        $row = $this->row($contact);
        $this->assertSame($this->client->id, $row->client_id);
        $this->assertSame($this->created->organization->id, $row->organization_id);
        $this->assertSame(['Kofi Owusu', 'Brother', '+233244100002', 'kofi@example.org', true, 'Works nights.'],
            [$row->name, $row->relationship, $row->phone, $row->email, $row->is_emergency_contact, $row->notes]);
    }

    #[Test]
    public function new_contacts_go_to_the_end_of_the_list(): void
    {
        $a = $this->save(['name' => 'Zed']);
        $b = $this->save(['name' => 'Amy']);
        $c = $this->save(['name' => 'Bob']);

        $this->assertSame([1, 2, 3], [$this->row($a)->sort, $this->row($b)->sort, $this->row($c)->sort]);
        $this->assertSame(['Zed', 'Amy', 'Bob'], $this->inTenant($this->created->organization, fn () => $this->client->contacts()->pluck('name')->all()));
    }

    #[Test]
    public function it_audits_a_new_contact_without_its_notes(): void
    {
        $contact = $this->save(['name' => 'Kofi Owusu', 'relationship' => 'Brother', 'phone' => '0244100002', 'notes' => 'Works nights.']);

        $entry = $this->auditEntries('client_contact.created')->first();
        $this->assertSame('client_contact', $entry->subject_type);
        $this->assertSame($contact->id, $entry->subject_id);
        $this->assertSame($this->created->organization->id, $entry->organization_id);
        $this->assertSame($this->created->ownerMembership->user_id, $entry->actor_user_id);
        $this->assertSame($this->client->id, $entry->metadata['client_id']);
        $this->assertSame('Contact added to client '.$this->client->formattedNumber(), $entry->summary);
        $this->assertSame('Brother', $entry->after['relationship']);
        $this->assertStringNotContainsString('nights', json_encode($entry));
    }

    #[Test]
    public function it_changes_a_contact_audits_the_difference_and_keeps_the_callers_record_current(): void
    {
        $contact = $this->save(['name' => 'Kofi Owusu', 'relationship' => 'Brother', 'phone' => '0244100002', 'notes' => 'Works nights.']);

        $returned = $this->save(['name' => 'Kofi Owusu', 'relationship' => 'Cousin', 'phone' => '+233244100003', 'notes' => 'Works days.'], $contact);

        $this->assertSame($contact, $returned);
        $this->assertSame('Cousin', $contact->relationship);

        $row = $this->row($contact);
        $this->assertSame(['Cousin', '+233244100003', 'Works days.'], [$row->relationship, $row->phone, $row->notes]);

        $entry = $this->auditEntries('client_contact.updated')->first();
        $this->assertEquals(['relationship' => 'Brother', 'phone' => '+233244100002'], $entry->before);
        $this->assertEquals(['relationship' => 'Cousin', 'phone' => '+233244100003'], $entry->after);
        $this->assertContains('notes', $entry->metadata['fields']);
        $this->assertStringNotContainsString('nights', json_encode($entry));
        $this->assertStringNotContainsString('days', json_encode($entry));
    }

    #[Test]
    public function a_notes_only_change_is_audited_without_the_notes_and_an_identical_save_is_not_audited_at_all(): void
    {
        $contact = $this->save(['name' => 'Kofi Owusu', 'notes' => 'Works nights.']);

        $this->save(['name' => 'Kofi Owusu', 'notes' => 'Works nights.'], $contact);
        $this->assertCount(0, $this->auditEntries('client_contact.updated'));

        $this->save(['name' => 'Kofi Owusu', 'notes' => 'Works days.'], $contact);
        $entries = $this->auditEntries('client_contact.updated');
        $this->assertCount(1, $entries);
        $this->assertSame(['notes'], $entries->first()->metadata['fields']);
        $this->assertNull($entries->first()->before);
    }

    #[Test]
    public function the_emergency_flag_can_be_set_and_cleared(): void
    {
        $contact = $this->save(['name' => 'Kofi', 'is_emergency_contact' => true]);
        $this->assertTrue($this->row($contact)->is_emergency_contact);

        $this->save(['name' => 'Kofi', 'is_emergency_contact' => '0'], $contact);
        $this->assertFalse($this->row($contact)->is_emergency_contact);
    }

    #[Test]
    public function a_contact_needs_a_name_and_valid_details(): void
    {
        foreach ([
            ['name', ['name' => '   ']],
            ['name', ['name' => null]],
            ['email', ['name' => 'Kofi', 'email' => 'nope']],
            ['phone', ['name' => 'Kofi', 'phone' => 'call me']],
        ] as [$field, $input]) {
            try {
                $this->save($input);
                $this->fail("[{$field}] should have been refused");
            } catch (DomainException $e) {
                $this->assertSame($field, $e->field());
            }
        }

        $this->assertSame(0, DB::table('client_contacts')->count());
    }

    #[Test]
    public function a_client_holds_a_bounded_number_of_contacts(): void
    {
        $other = $this->clientIn($this->created->organization);

        for ($i = 1; $i <= SaveClientContact::MAX_PER_CLIENT; $i++) {
            $last = $this->save(['name' => "Contact {$i}"]);
        }

        try {
            $this->save(['name' => 'One too many']);
            $this->fail('The limit should hold.');
        } catch (DomainException $e) {
            $this->assertSame('too_many_contacts', $e->errorCode());
        }

        $this->assertSame(SaveClientContact::MAX_PER_CLIENT, DB::table('client_contacts')->where('client_id', $this->client->id)->count());
        $this->assertNotNull($this->save(['name' => 'Fine'], null, $other), 'the limit is per client');

        $this->remove($last);
        $this->assertNotNull($this->save(['name' => 'Now there is room']));
    }

    #[Test]
    public function a_contact_can_only_be_changed_through_its_own_client(): void
    {
        $contact = $this->save(['name' => 'Kofi']);
        $otherClient = $this->clientIn($this->created->organization);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('does not belong to this client');

        $this->save(['name' => 'Moved'], $contact, $otherClient);
    }

    #[Test]
    public function removing_a_contact_deletes_it_and_audits_what_it_was(): void
    {
        $contact = $this->save(['name' => 'Kofi Owusu', 'relationship' => 'Brother', 'phone' => '0244100002', 'email' => 'kofi@example.org', 'is_emergency_contact' => true, 'notes' => 'Works nights.']);

        $this->remove($contact);

        $this->assertNull($this->row($contact));

        $entry = $this->auditEntries('client_contact.deleted')->first();
        $this->assertSame($contact->id, $entry->subject_id);
        $this->assertSame($this->created->ownerMembership->user_id, $entry->actor_user_id);
        $this->assertEquals(['name' => 'Kofi Owusu', 'relationship' => 'Brother', 'phone' => '+233244100002', 'email' => 'kofi@example.org', 'is_emergency_contact' => true], $entry->before);
        $this->assertStringNotContainsString('nights', json_encode($entry));
    }

    #[Test]
    public function a_contact_cannot_be_removed_through_another_client(): void
    {
        $contact = $this->save(['name' => 'Kofi']);
        $otherClient = $this->clientIn($this->created->organization);

        try {
            $this->remove($contact, $otherClient);
            $this->fail('The contact is not that client\'s.');
        } catch (ModelNotFoundException) {
            // expected
        }

        $this->assertNotNull($this->row($contact));
        $this->assertCount(0, $this->auditEntries('client_contact.deleted'));
    }

    #[Test]
    public function another_organizations_clients_and_contacts_are_out_of_reach(): void
    {
        $other = $this->createOrganization();
        $foreignClient = $this->clientIn($other->organization);
        $foreignContact = $this->save(['name' => 'Yaw'], null, $foreignClient, $other);

        // add to a foreign client
        try {
            $this->save(['name' => 'Intruder'], null, $foreignClient);
            $this->fail('A client of another organization must not be found.');
        } catch (ModelNotFoundException) {
        }

        // change and remove a foreign contact
        try {
            $this->save(['name' => 'Hijacked'], $foreignContact, $foreignClient);
            $this->fail('A contact of another organization must not be found.');
        } catch (ModelNotFoundException) {
        }
        try {
            $this->remove($foreignContact, $foreignClient);
            $this->fail('A contact of another organization must not be found.');
        } catch (ModelNotFoundException) {
        }

        $this->assertSame(['Yaw'], DB::table('client_contacts')->where('client_id', $foreignClient->id)->pluck('name')->all());
    }
}
