<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\CreateClient;
use App\Domain\Clients\DeleteClientContact;
use App\Domain\Clients\SaveClientContact;
use App\Domain\Clients\UpdateClient;
use App\Domain\Platform\CreatedOrganization;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\DomainException;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * A minor needs a reachable parent or guardian on file: when registered, when a person becomes a minor, and
 * for as long as they are one (the last guardian cannot be removed or turned into something else).
 */
class MinorGuardianTest extends ClientsTestCase
{
    private CreatedOrganization $created;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();
        // Not under test here (ClientContactPointsTest covers it): clients may be registered without an e-mail or phone.
        app(SettingsService::class)->setOrganization($this->created->organization, ['clients.require_contact' => false], null);
    }

    private function as(callable $callback): mixed
    {
        return $this->actAs($this->created->ownerMembership, $this->created->organization, $callback);
    }

    private function actor(): User
    {
        return User::query()->findOrFail($this->created->ownerMembership->user_id);
    }

    /** @param array<string, mixed> $input */
    private function create(array $input): Client
    {
        return $this->as(fn () => app(CreateClient::class)($input + ['first_name' => 'Kwesi', 'last_name' => 'Owusu'], $this->actor()));
    }

    private function refused(string $code, callable $attempt): DomainException
    {
        try {
            $attempt();
        } catch (DomainException $e) {
            $this->assertSame($code, $e->errorCode(), $e->userMessage());

            return $e;
        }
        $this->fail("Expected the refusal [{$code}].");
    }

    #[Test]
    public function a_minor_without_a_reachable_guardian_is_refused_and_nothing_is_written(): void
    {
        $this->refused('guardian_required', fn () => $this->create(['client_type' => 'minor']));
        $this->refused('guardian_required', fn () => $this->create(['client_type' => 'minor', 'guardians' => [['name' => '', 'phone' => '']]]));
        $e = $this->refused('guardian_contact_required', fn () => $this->create(['client_type' => 'minor', 'guardians' => ['g1' => ['name' => 'Akosua Owusu']]]));
        $this->assertSame('guardians.g1.phone', $e->field());
        $this->refused('guardian_name_required', fn () => $this->create(['client_type' => 'minor', 'guardians' => [['phone' => '0244100001']]]));
        $this->refused('invalid_guardian_type', fn () => $this->create(['client_type' => 'minor', 'guardians' => [['name' => 'A', 'phone' => '0244100001', 'relationship_type' => 'friend']]]));

        $this->assertSame(0, DB::table('clients')->count());
        $this->assertSame(0, DB::table('client_contacts')->count());
    }

    #[Test]
    public function a_minor_is_registered_with_their_guardians_as_contacts(): void
    {
        $client = $this->create(['client_type' => 'minor', 'date_of_birth' => '2014-02-01', 'guardians' => [
            ['name' => 'Akosua Owusu', 'relationship_type' => 'parent', 'phone' => '024 410 0001', 'is_emergency_contact' => '1'],
            ['name' => 'Yaw Boateng', 'relationship_type' => 'guardian', 'email' => 'Yaw@Example.org'],
        ]]);

        $this->assertTrue($client->isMinor());
        $contacts = DB::table('client_contacts')->where('client_id', $client->id)->orderBy('sort')->get();
        $this->assertSame(['Akosua Owusu', 'Yaw Boateng'], $contacts->pluck('name')->all());
        $this->assertSame(['parent', 'guardian'], $contacts->pluck('relationship_type')->all());
        $this->assertSame(['Parent', 'Guardian'], $contacts->pluck('relationship')->all());
        $this->assertSame('+233244100001', $contacts[0]->phone);
        $this->assertTrue((bool) $contacts[0]->is_emergency_contact);
        $this->assertSame('yaw@example.org', $contacts[1]->email);
        $this->assertSame(2, $this->auditEntries('client.created')->first()->metadata['guardians']);
    }

    #[Test]
    public function guardian_rows_are_ignored_for_an_adult(): void
    {
        $client = $this->create(['client_type' => 'adult', 'guardians' => [['name' => 'Not Needed', 'phone' => '0244100001']]]);

        $this->assertSame('adult', $client->client_type->value);
        $this->assertSame(0, DB::table('client_contacts')->count());
    }

    #[Test]
    public function becoming_a_minor_needs_a_guardian_on_file_or_in_the_same_change(): void
    {
        $client = $this->create([]);
        $update = fn (array $input) => $this->as(fn () => app(UpdateClient::class)($client, $input, $this->actor()));

        $this->refused('guardian_required', fn () => $update(['client_type' => 'minor']));
        $this->assertSame('adult', DB::table('clients')->where('id', $client->id)->value('client_type'));

        $update(['client_type' => 'minor', 'guardians' => [['name' => 'Akosua Owusu', 'phone' => '0244100001']]]);
        $this->assertTrue($client->isMinor());
        $this->assertSame(1, DB::table('client_contacts')->where('client_id', $client->id)->count());

        // Back to adult and minor again: the guardian already on file is enough.
        $update(['client_type' => 'adult']);
        $update(['client_type' => 'minor']);
        $this->assertTrue($client->isMinor());
        $this->assertSame(['client_type' => 'adult'], $this->auditEntries('client.updated')->last()->before);
    }

    #[Test]
    public function a_person_never_becomes_a_couple_by_editing(): void
    {
        $client = $this->create([]);

        $this->refused('couple_needs_members', fn () => $this->as(fn () => app(UpdateClient::class)($client, ['client_type' => 'couple'], $this->actor())));
        $this->refused('couple_needs_members', fn () => $this->create(['client_type' => 'couple']));
    }

    #[Test]
    public function a_minors_last_guardian_cannot_be_removed_or_changed_into_something_else(): void
    {
        $client = $this->create(['client_type' => 'minor', 'guardians' => [['name' => 'Akosua Owusu', 'phone' => '0244100001']]]);
        $guardian = $this->inTenant($this->created->organization, fn () => ClientContact::query()->where('client_id', $client->id)->firstOrFail());

        $this->refused('guardian_required', fn () => $this->as(fn () => app(DeleteClientContact::class)($client, $guardian)));
        $this->refused('guardian_required', fn () => $this->as(fn () => app(SaveClientContact::class)($client, ['relationship_type' => 'friend'], $guardian)));
        $this->refused('guardian_required', fn () => $this->as(fn () => app(SaveClientContact::class)($client, ['phone' => '', 'email' => ''], $guardian)));
        $this->assertSame(1, DB::table('client_contacts')->where('client_id', $client->id)->count());

        // With a second guardian, either may go.
        $this->as(fn () => app(SaveClientContact::class)($client, ['name' => 'Yaw Boateng', 'relationship_type' => 'guardian', 'email' => 'yaw@example.org']));
        $this->as(fn () => app(DeleteClientContact::class)($client, $guardian));
        $this->assertSame(['Yaw Boateng'], DB::table('client_contacts')->where('client_id', $client->id)->pluck('name')->all());
    }

    #[Test]
    public function an_adults_contacts_have_no_such_rule(): void
    {
        $client = $this->create([]);
        $contact = $this->as(fn () => app(SaveClientContact::class)($client, ['name' => 'Akosua Owusu', 'relationship_type' => 'parent', 'phone' => '0244100001']));

        $this->as(fn () => app(DeleteClientContact::class)($client, $contact));
        $this->assertSame(0, DB::table('client_contacts')->count());
    }

    #[Test]
    public function a_contacts_relationship_type_is_checked(): void
    {
        $client = $this->create([]);

        $this->refused('invalid_relationship_type', fn () => $this->as(fn () => app(SaveClientContact::class)($client, ['name' => 'X', 'relationship_type' => 'cousin'])));
        $contact = $this->as(fn () => app(SaveClientContact::class)($client, ['name' => 'Kojo', 'relationship_type' => 'spouse', 'relationship' => 'Husband']));
        $this->assertSame('spouse', $contact->relationship_type->value);
        $this->assertSame('Husband', $contact->relationshipLabel());
    }
}
