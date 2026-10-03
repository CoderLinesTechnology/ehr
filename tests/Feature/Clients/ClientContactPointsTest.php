<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\ClientSearch;
use App\Domain\Clients\CreateClient;
use App\Domain\Clients\UpdateClient;
use App\Domain\Platform\CreatedOrganization;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\DomainException;
use App\Models\Client;
use App\Models\ClientContactPoint;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Several e-mails and phones per client (client_contact_points), the primary mirrored on clients.email /
 * clients.phone, search over all of them, billing type and the "virtual" primary location.
 */
class ClientContactPointsTest extends ClientsTestCase
{
    private CreatedOrganization $created;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();
        // Not under test here (ClientContactPointsTest covers it): clients may be registered without an e-mail or phone.
        app(SettingsService::class)->setOrganization($this->created->organization, ['clients.require_contact' => false], null);
    }

    /** @param array<string, mixed> $input */
    private function create(array $input): Client
    {
        $actor = User::query()->findOrFail($this->created->ownerMembership->user_id);

        return $this->actAs($this->created->ownerMembership, $this->created->organization, fn () => app(CreateClient::class)($input + ['first_name' => 'Ama', 'last_name' => 'Owusu'], $actor));
    }

    /** @param array<string, mixed> $input */
    private function update(Client $client, array $input): Client
    {
        $actor = User::query()->findOrFail($this->created->ownerMembership->user_id);

        return $this->actAs($this->created->ownerMembership, $this->created->organization, fn () => app(UpdateClient::class)($client, $input, $actor));
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: bool}> kind, value, label, primary — in display order */
    private function points(Client $client): array
    {
        return DB::table('client_contact_points')->where('client_id', $client->id)->orderBy('kind')->orderByDesc('is_primary')->orderBy('sort')->get()
            ->map(fn ($p) => [$p->kind, $p->value, $p->label, (bool) $p->is_primary])->all();
    }

    private function refused(string $field, callable $attempt): DomainException
    {
        try {
            $attempt();
        } catch (DomainException $e) {
            $this->assertSame($field, $e->field(), $e->userMessage());

            return $e;
        }
        $this->fail("Expected a refusal on [{$field}].");
    }

    #[Test]
    public function a_client_gets_several_emails_and_phones_normalised_with_the_chosen_primary_mirrored(): void
    {
        $client = $this->create([
            'emails' => ['0' => ['value' => 'Ama@Example.org', 'label' => 'home'], '1' => ['value' => 'ama@work.example.org', 'label' => 'work'], '2' => ['value' => '']],
            'primary_email' => '1',
            'phones' => ['a' => ['value' => '024 410 0001', 'label' => 'mobile'], 'b' => ['value' => '+233 30 212 3456', 'label' => 'home']],
            'primary_phone' => 'a',
        ]);

        $this->assertSame([
            ['email', 'ama@work.example.org', 'work', true],
            ['email', 'ama@example.org', 'home', false],
            ['phone', '+233244100001', 'mobile', true],
            ['phone', '+233302123456', 'home', false],
        ], $this->points($client));
        $this->assertSame('ama@work.example.org', $client->email);
        $this->assertSame('+233244100001', $client->phone);
    }

    #[Test]
    public function the_first_row_is_primary_when_none_is_chosen_and_blank_rows_are_ignored(): void
    {
        $client = $this->create(['emails' => [['value' => ''], ['value' => 'first@example.org'], ['value' => 'second@example.org']]]);

        $this->assertSame([['email', 'first@example.org', 'home', true], ['email', 'second@example.org', 'home', false]], $this->points($client));
        $this->assertSame(1, DB::table('client_contact_points')->where('client_id', $client->id)->where('is_primary', true)->count());
    }

    #[Test]
    public function duplicates_bad_values_bad_labels_and_too_many_rows_are_refused_with_the_row_named(): void
    {
        $this->refused('emails.1.value', fn () => $this->create(['emails' => [['value' => 'a@example.org'], ['value' => 'A@Example.org']]]));
        $this->refused('phones.1.value', fn () => $this->create(['phones' => [['value' => '0244100001'], ['value' => '+233 24 410 0001']]]));
        $this->refused('emails.0.value', fn () => $this->create(['emails' => [['value' => 'not-an-email']]]));
        $this->refused('phones.0.value', fn () => $this->create(['phones' => [['value' => '12']]]));
        $this->refused('emails.0.label', fn () => $this->create(['emails' => [['value' => 'a@example.org', 'label' => 'mobile']]]));
        $this->refused('emails', fn () => $this->create(['emails' => array_map(fn ($i) => ['value' => "p{$i}@example.org"], range(1, 11))]));

        $this->assertSame(0, $this->inTenant($this->created->organization, fn () => Client::query()->count()), 'nothing was written');
    }

    #[Test]
    public function the_contact_requirement_is_met_by_any_email_or_phone(): void
    {
        app(SettingsService::class)->setOrganization($this->created->organization, ['clients.require_contact' => true], null);

        $this->refused('email', fn () => $this->create(['emails' => [['value' => '']], 'phones' => [['value' => '']]]));
        $client = $this->create(['phones' => [['value' => '0244100002', 'label' => 'work']]]);

        $this->assertNull($client->email);
        $this->assertSame('+233244100002', $client->phone);
    }

    #[Test]
    public function the_single_value_input_sets_the_primary_and_keeps_the_others(): void
    {
        $client = $this->create(['emails' => [['value' => 'a@example.org'], ['value' => 'b@example.org', 'label' => 'work']]]);

        $this->update($client, ['email' => 'c@example.org']);
        $this->assertSame([['email', 'c@example.org', 'home', true], ['email', 'b@example.org', 'work', false]], $this->points($client));
        $this->assertSame('c@example.org', $client->email);

        // Blank removes the primary; the next one takes its place.
        $this->update($client, ['email' => '']);
        $this->assertSame([['email', 'b@example.org', 'work', true]], $this->points($client));
        $this->assertSame('b@example.org', $client->email);
    }

    #[Test]
    public function an_update_replaces_the_lists_and_audits_kinds_and_counts_never_the_values(): void
    {
        $client = $this->create(['emails' => [['value' => 'a@example.org']], 'phones' => [['value' => '0244100001']]]);

        $this->update($client, [
            'emails' => [['value' => 'a@example.org'], ['value' => 'new@example.org', 'label' => 'work']], 'primary_email' => '1',
            'phones' => [['value' => '0244100001']],
        ]);

        $this->assertSame('new@example.org', $client->email);
        $entry = $this->auditEntries('client.updated')->last();
        $this->assertSame(['email'], $entry->metadata['contact_points_changed']);
        $this->assertSame(['email' => 2, 'phone' => 1], $entry->metadata['contact_points']);
        $serialised = json_encode($entry);
        foreach (['a@example.org', 'new@example.org', '+233244100001'] as $value) {
            $this->assertStringNotContainsString($value, $serialised, "the audit trail must not hold [{$value}]");
        }

        // The same lists again: nothing changes, nothing is audited.
        $count = $this->auditEntries('client.updated')->count();
        $this->update($client, ['emails' => [['value' => 'A@example.org'], ['value' => 'new@example.org', 'label' => 'work']], 'primary_email' => '1', 'phones' => [['value' => '+233 24 410 0001']]]);
        $this->assertSame($count, $this->auditEntries('client.updated')->count());
    }

    #[Test]
    public function creation_is_audited_with_counts_and_the_kind_of_record_only(): void
    {
        $this->create(['emails' => [['value' => 'a@example.org'], ['value' => 'b@example.org']], 'phones' => [['value' => '0244100001']], 'billing_type' => 'insurance']);

        $entry = $this->auditEntries('client.created')->first();
        $this->assertSame(['email' => 2, 'phone' => 1], $entry->metadata['contact_points']);
        $this->assertSame('adult', $entry->metadata['client_type']);
        $this->assertSame('insurance', $entry->metadata['billing_type']);
        $this->assertStringNotContainsString('a@example.org', json_encode($entry));
    }

    #[Test]
    public function search_finds_a_client_by_any_of_their_emails_and_phones(): void
    {
        $client = $this->create([
            'emails' => [['value' => 'ama@example.org'], ['value' => 'ama.owusu@corporate.example.org', 'label' => 'work']],
            'phones' => [['value' => '0244100001'], ['value' => '030 212 3456', 'label' => 'home']],
        ]);
        $this->create(['first_name' => 'Kofi', 'last_name' => 'Mensah', 'emails' => [['value' => 'kofi@example.org']]]);

        $find = fn (string $term) => $this->inTenant($this->created->organization, fn () => ClientSearch::apply(Client::query(), $term)->pluck('id')->all(), $this->created->ownerMembership);

        $this->assertSame([$client->id], $find('corporate'), 'a secondary email');
        $this->assertSame([$client->id], $find('030 212 3456'), 'a secondary phone typed locally');

        // Removing the point removes it from search (the trigger keeps clients.contact_search current).
        $this->update($client, ['emails' => [['value' => 'ama@example.org']]]);
        $this->assertSame([], $find('corporate'));
    }

    #[Test]
    public function billing_defaults_to_self_pay_and_can_be_insurance(): void
    {
        $this->assertSame('self_pay', $this->create([])->billing_type->value);
        $client = $this->create(['billing_type' => 'insurance']);
        $this->assertSame('insurance', $client->billing_type->value);

        $this->refused('billing_type', fn () => $this->create(['billing_type' => 'cash']));

        $this->update($client, ['billing_type' => 'self_pay']);
        $this->assertSame('self_pay', $client->billing_type->value);
        $this->assertSame(['billing_type' => 'insurance'], $this->auditEntries('client.updated')->last()->before);
    }

    #[Test]
    public function the_primary_location_can_be_virtual_instead_of_a_place(): void
    {
        $location = $this->inTenant($this->created->organization, fn () => Location::factory()->create());

        $client = $this->create(['primary_location_id' => 'virtual']);
        $this->assertTrue($client->is_virtual);
        $this->assertNull($client->primary_location_id);

        $this->update($client, ['primary_location_id' => $location->id]);
        $this->assertFalse($client->is_virtual);
        $this->assertSame($location->id, $client->primary_location_id);

        $this->update($client, ['is_virtual' => true]);
        $this->assertTrue($client->is_virtual);
        $this->assertNull($client->primary_location_id);

        // The database refuses both at once, whatever the application does.
        $this->expectException(QueryException::class);
        DB::table('clients')->where('id', $client->id)->update(['primary_location_id' => $location->id]);
    }

    #[Test]
    public function a_point_belongs_to_one_client_of_one_organization_and_one_primary_per_kind(): void
    {
        $client = $this->create(['emails' => [['value' => 'a@example.org']]]);

        $this->expectException(QueryException::class);
        $this->inTenant($this->created->organization, function () use ($client) {
            $point = new ClientContactPoint;
            $point->forceFill(['client_id' => $client->id, 'kind' => 'email', 'value' => 'b@example.org', 'label' => 'home', 'is_primary' => true])->save();
        });
    }
}
