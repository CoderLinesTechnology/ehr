<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\ClientStatus;
use App\Domain\Clients\CreateClient;
use App\Domain\Clients\Events\ClientCreated;
use App\Domain\Platform\CreatedOrganization;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Saas\LimitReached;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\RecordEnvironment;
use App\Domain\Tenancy\MissingTenantContext;
use App\Models\Client;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

class CreateClientTest extends ClientsTestCase
{
    private CreatedOrganization $created;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();
    }

    /** @param array<string, mixed> $input */
    private function create(array $input = [], ?CreatedOrganization $in = null): Client
    {
        $in ??= $this->created;
        $actor = User::query()->findOrFail($in->ownerMembership->user_id);

        return $this->actAs($in->ownerMembership, $in->organization, fn () => app(CreateClient::class)($input + [
            'first_name' => 'Ama',
            'last_name' => 'Owusu',
            'email' => 'ama@example.org',
        ], $actor));
    }

    private function expectRefusal(string $field, string $code, callable $attempt): DomainException
    {
        try {
            $attempt();
        } catch (DomainException $e) {
            $this->assertSame($field, $e->field(), $e->userMessage());
            $this->assertSame($code, $e->errorCode());

            return $e;
        }

        $this->fail("Expected a DomainException on [{$field}] ({$code}).");
    }

    private function setSetting(string $key, mixed $value): void
    {
        app(SettingsService::class)->setOrganization($this->created->organization, [$key => $value], null);
    }

    // ── numbering and the fields the caller cannot choose ───────────────

    #[Test]
    public function it_creates_a_live_active_client_numbered_within_its_own_organization(): void
    {
        $other = $this->createOrganization();

        $first = $this->create();
        $second = $this->create(['first_name' => 'Kofi', 'email' => 'kofi@example.org']);
        $third = $this->create(['first_name' => 'Esi', 'email' => 'esi@example.org']);
        $inOther = $this->create(['first_name' => 'Yaw', 'email' => 'yaw@example.org'], $other);

        $this->assertSame([1, 2, 3], [$first->client_number, $second->client_number, $third->client_number]);
        $this->assertMatchesRegularExpression('/^[A-Z]+-0*1$/', $first->formattedNumber());
        $this->assertSame(1, $inOther->client_number, 'every organization numbers its own clients from 1');

        $this->assertSame(RecordEnvironment::Live, $first->record_environment);
        $this->assertSame(ClientStatus::Active, $first->status);
        $this->assertSame($this->created->organization->id, $first->organization_id);
        $this->assertSame($other->organization->id, $inOther->organization_id);
    }

    #[Test]
    public function status_number_environment_organization_and_author_cannot_be_chosen_by_the_caller(): void
    {
        $other = $this->createOrganization();
        $stranger = User::factory()->create();

        $client = $this->create([
            'status' => 'archived',
            'client_number' => 999,
            'record_environment' => 'demo',
            'organization_id' => $other->organization->id,
            'created_by_user_id' => $stranger->id,
            'archived_at' => now()->toDateTimeString(),
            'id' => '00000000-0000-7000-8000-000000000001',
        ]);

        $this->assertSame(ClientStatus::Active, $client->status);
        $this->assertSame(1, $client->client_number);
        $this->assertSame(RecordEnvironment::Live, $client->record_environment);
        $this->assertSame($this->created->organization->id, $client->organization_id);
        $this->assertSame($this->created->ownerMembership->user_id, $client->created_by_user_id);
        $this->assertNull($client->archived_at);
        $this->assertNotSame('00000000-0000-7000-8000-000000000001', $client->id);
    }

    #[Test]
    public function it_returns_a_complete_record(): void
    {
        $client = $this->create();

        // A freshly saved model holds only what was written; strict mode would throw on the rest.
        $this->assertNull($client->phone);
        $this->assertNull($client->archived_at);
        $this->assertNotNull($client->created_at);
    }

    #[Test]
    public function it_tidies_what_it_stores(): void
    {
        $client = $this->create([
            'first_name' => '  Ama ',
            'last_name' => "\tOwusu",
            'middle_name' => '   ',
            'email' => '  AMA.Owusu@Example.ORG ',
            'country_code' => 'gh',
            'administrative_notes' => "  Prefers mornings.\n",
        ]);

        $this->assertSame('Ama', $client->first_name);
        $this->assertSame('Owusu', $client->last_name);
        $this->assertNull($client->middle_name, 'blank becomes NULL');
        $this->assertSame('ama.owusu@example.org', $client->email);
        $this->assertSame('GH', $client->country_code);
        $this->assertSame('Prefers mornings.', $client->administrative_notes);
    }

    #[Test]
    public function it_needs_an_organization_context(): void
    {
        $this->expectException(MissingTenantContext::class);

        app(CreateClient::class)(['first_name' => 'Ama', 'last_name' => 'Owusu', 'email' => 'ama@example.org']);
    }

    // ── audit, timeline, event ──────────────────────────────────────────

    #[Test]
    public function it_audits_the_creation_without_putting_personal_details_in_the_trail(): void
    {
        $client = $this->create(['date_of_birth' => '1990-03-12', 'phone' => '0244100001']);

        $entries = $this->auditEntries('client.created');
        $this->assertCount(1, $entries);

        $entry = $entries->first();
        $this->assertSame('client', $entry->subject_type);
        $this->assertSame($client->id, $entry->subject_id);
        $this->assertSame($this->created->organization->id, $entry->organization_id);
        $this->assertSame($this->created->ownerMembership->user_id, $entry->actor_user_id);
        $this->assertSame('Client '.$client->formattedNumber().' created', $entry->summary);
        $this->assertEquals(['client_number' => 1, 'status' => 'active'], $entry->after);

        $serialised = json_encode($entry);
        foreach (['Ama', 'Owusu', 'ama@example.org', '1990', '+233244100001'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialised, "the audit trail must not hold [{$secret}]");
        }
    }

    #[Test]
    public function the_first_timeline_entry_is_written_after_commit_by_the_listener(): void
    {
        $client = $this->create();

        $entries = DB::table('timeline_entries')->where('client_id', $client->id)->get();

        $this->assertCount(1, $entries);
        $this->assertSame('administrative', $entries[0]->category);
        $this->assertSame('client.created', $entries[0]->type);
        $this->assertSame('Client record created', $entries[0]->summary);
        $this->assertSame('live', $entries[0]->record_environment);
        $this->assertSame($this->created->ownerMembership->user_id, $entries[0]->actor_user_id);
        $this->assertSame('client', $entries[0]->subject_type);
        $this->assertSame($client->id, $entries[0]->subject_id);
    }

    #[Test]
    public function it_dispatches_client_created_with_the_record_and_the_actor(): void
    {
        Event::fake([ClientCreated::class]);

        $client = $this->create();

        Event::assertDispatched(ClientCreated::class, fn (ClientCreated $event) => $event->client->is($client)
            && $event->actorUserId === $this->created->ownerMembership->user_id);
    }

    #[Test]
    public function nothing_persists_when_any_part_of_the_creation_fails(): void
    {
        // Make the audit insert fail: the client, its number and its timeline entry must all roll back with it.
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT test_refuse_client_created CHECK (action <> 'client.created')");

        try {
            $this->create();
            $this->fail('The audit failure should have propagated.');
        } catch (\Illuminate\Database\QueryException) {
            // expected
        } finally {
            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT test_refuse_client_created');
        }

        $this->assertSame(0, DB::table('clients')->count());
        $this->assertSame(0, DB::table('timeline_entries')->count());
        $this->assertNull(DB::table('organization_counters')->where('organization_id', $this->created->organization->id)->where('key', 'client')->value('value'),
            'the client number goes back with the rollback');

        // The next client is number 1: no gap.
        $this->assertSame(1, $this->create()->client_number);
    }

    // ── fields the organization insists on ──────────────────────────────

    #[Test]
    public function a_new_client_needs_an_email_or_a_phone_number_by_default(): void
    {
        $this->expectRefusal('email', 'contact_required', fn () => $this->create(['email' => null, 'phone' => null]));
        $this->expectRefusal('email', 'contact_required', fn () => $this->create(['email' => '  ', 'phone' => '']));

        $this->assertSame(0, DB::table('clients')->count());

        // either one is enough
        $this->assertNotNull($this->create(['email' => null, 'phone' => '0244100001']));
        $this->assertNotNull($this->create(['email' => 'x@example.org', 'phone' => null]));
    }

    #[Test]
    public function the_contact_requirement_can_be_switched_off(): void
    {
        $this->setSetting('clients.require_contact', false);

        $client = $this->create(['email' => null, 'phone' => null]);

        $this->assertNull($client->email);
        $this->assertNull($client->phone);
    }

    #[Test]
    public function the_date_of_birth_is_required_only_when_the_organization_says_so(): void
    {
        $this->assertNull($this->create()->date_of_birth);

        $this->setSetting('clients.require_date_of_birth', true);

        $this->expectRefusal('date_of_birth', 'date_of_birth_required', fn () => $this->create());
        $this->assertSame('1990-03-12', $this->create(['date_of_birth' => '1990-03-12'])->date_of_birth->toDateString());

        $this->setSetting('clients.require_date_of_birth', false);
        $this->assertNotNull($this->create());
    }

    #[Test]
    public function the_settings_apply_to_the_organization_that_set_them_only(): void
    {
        $other = $this->createOrganization();
        $this->setSetting('clients.require_date_of_birth', true);

        $this->expectRefusal('date_of_birth', 'date_of_birth_required', fn () => $this->create());
        $this->assertNotNull($this->create([], $other), 'the other organization still has the default');
    }

    // ── what a stored value must look like ──────────────────────────────

    #[Test]
    public function phone_numbers_are_stored_in_e164(): void
    {
        $this->assertSame('+233244100001', $this->create(['phone' => '024 410 0001'])->phone);
        $this->assertSame('+233244100002', $this->create(['phone' => '+233 24 410 0002'])->phone);
        $this->assertSame('+447911123456', $this->create(['phone' => '0044 7911 123456'])->phone, 'an international number is kept as typed, in E.164');
    }

    #[Test]
    public function a_phone_number_that_cannot_be_understood_is_refused(): void
    {
        foreach (['024410', '0244 CALL ME', '+23324', '02441000012'] as $bad) {
            $e = $this->expectRefusal('phone', 'invalid_phone', fn () => $this->create(['phone' => $bad]));
            $this->assertStringContainsString('phone number', $e->userMessage());
        }
    }

    #[Test]
    public function in_a_country_whose_format_is_unknown_the_plus_form_is_required(): void
    {
        $brazil = $this->createOrganization(['country_code' => 'BR']);

        $e = $this->expectRefusal('phone', 'invalid_phone', fn () => $this->create(['phone' => '011 91234 5678'], $brazil));
        $this->assertStringContainsString('+', $e->userMessage());

        $this->assertSame('+5511912345678', $this->create(['phone' => '+55 11 91234 5678'], $brazil)->phone);
    }

    #[Test]
    public function an_email_must_be_valid(): void
    {
        $this->expectRefusal('email', 'invalid_email', fn () => $this->create(['email' => 'not an email']));
    }

    #[Test]
    public function the_date_of_birth_must_be_a_real_date_that_is_not_in_the_future(): void
    {
        $tomorrow = CarbonImmutable::now('Africa/Accra')->addDay()->toDateString();
        $today = CarbonImmutable::now('Africa/Accra')->toDateString();

        $this->expectRefusal('date_of_birth', 'future_date_of_birth', fn () => $this->create(['date_of_birth' => $tomorrow]));
        $this->expectRefusal('date_of_birth', 'invalid_date_of_birth', fn () => $this->create(['date_of_birth' => '31/12/1990']));
        $this->expectRefusal('date_of_birth', 'invalid_date_of_birth', fn () => $this->create(['date_of_birth' => '1990-02-30']));
        $this->expectRefusal('date_of_birth', 'invalid_date_of_birth', fn () => $this->create(['date_of_birth' => '1899-12-31']));

        $this->assertSame($today, $this->create(['date_of_birth' => $today])->date_of_birth->toDateString(), 'born today is fine');
    }

    #[Test]
    public function a_clients_own_time_zone_must_be_a_real_one(): void
    {
        $this->expectRefusal('timezone', 'invalid_timezone', fn () => $this->create(['timezone' => 'Mars/Olympus_Mons']));

        $this->assertSame('Europe/London', $this->create(['timezone' => 'Europe/London'])->timezone);
    }

    #[Test]
    public function sex_contact_method_and_country_must_be_from_their_lists(): void
    {
        $this->expectRefusal('sex', 'invalid_sex', fn () => $this->create(['sex' => 'robot']));
        $this->expectRefusal('preferred_contact_method', 'invalid_preferred_contact_method', fn () => $this->create(['preferred_contact_method' => 'pigeon']));
        $this->expectRefusal('country_code', 'invalid_country', fn () => $this->create(['country_code' => 'ZZ']));

        $client = $this->create(['sex' => 'female', 'preferred_contact_method' => 'sms', 'country_code' => 'NG']);
        $this->assertSame(['female', 'sms', 'NG'], [$client->sex, $client->preferred_contact_method, $client->country_code]);
    }

    // ── who and where a client may be assigned to ───────────────────────

    #[Test]
    public function the_primary_clinician_must_be_an_active_provider_of_this_organization(): void
    {
        $org = $this->created->organization;
        $provider = $this->addStaff($org, 'clinician');
        $notProvider = $this->addStaff($org, 'receptionist', ['is_provider' => false]);
        $left = $this->addStaff($org, 'clinician');
        $this->inTenant($org, fn () => OrganizationMembership::query()->whereKey($left->id)->first()->forceFill(['status' => 'deactivated'])->save());
        $elsewhere = $this->addStaff($this->createOrganization()->organization, 'clinician');

        $this->assertSame($provider->id, $this->create(['primary_clinician_membership_id' => $provider->id])->primary_clinician_membership_id);

        foreach ([$notProvider->id, $left->id, $elsewhere->id, 'not-a-uuid'] as $bad) {
            $this->expectRefusal('primary_clinician_membership_id', 'invalid_clinician', fn () => $this->create(['primary_clinician_membership_id' => $bad]));
        }
    }

    #[Test]
    public function the_primary_location_must_be_an_active_location_of_this_organization(): void
    {
        $org = $this->created->organization;
        $open = $this->inTenant($org, fn () => Location::factory()->create());
        $closed = $this->inTenant($org, fn () => Location::factory()->create(['is_active' => false]));
        $elsewhere = $this->inTenant($this->createOrganization()->organization, fn () => Location::factory()->create());

        $this->assertSame($open->id, $this->create(['primary_location_id' => $open->id])->primary_location_id);

        foreach ([$closed->id, $elsewhere->id, 'not-a-uuid'] as $bad) {
            $this->expectRefusal('primary_location_id', 'invalid_location', fn () => $this->create(['primary_location_id' => $bad]));
        }
    }

    // ── the plan's active-client limit ──────────────────────────────────

    #[Test]
    public function the_starter_plan_allows_150_active_clients(): void
    {
        $starter = $this->createOrganization(plan: 'starter');

        $this->assertSame(150, app(EntitlementService::class)->limit($starter->organization, FeatureRegistry::MAX_ACTIVE_CLIENTS));
    }

    #[Test]
    public function creating_past_the_limit_is_refused_with_a_clear_message_and_changes_nothing(): void
    {
        $starter = $this->createOrganization(plan: 'starter');
        $this->limitActiveClientsTo($starter->organization, 1);

        $first = $this->create([], $starter);

        try {
            $this->create(['first_name' => 'Kofi'], $starter);
            $this->fail('The second client should be refused.');
        } catch (LimitReached $e) {
            $this->assertStringContainsString('1 active clients', $e->userMessage());
            $this->assertSame('limit_reached', $e->errorCode());
        }

        $this->assertSame(1, DB::table('clients')->where('organization_id', $starter->organization->id)->count());
        $this->assertSame(1, $first->client_number);
    }

    #[Test]
    public function a_refused_creation_does_not_use_up_a_client_number(): void
    {
        $starter = $this->createOrganization(plan: 'starter');
        $this->limitActiveClientsTo($starter->organization, 1);

        $this->create([], $starter);
        try {
            $this->create(['first_name' => 'Kofi'], $starter);
        } catch (LimitReached) {
            // expected
        }

        $this->limitActiveClientsTo($starter->organization, 5);

        $this->assertSame(2, $this->create(['first_name' => 'Esi'], $starter)->client_number, 'no gap in the sequence');
    }

    #[Test]
    public function demo_clients_never_count_towards_the_limit(): void
    {
        $starter = $this->createOrganization(plan: 'starter');
        $this->limitActiveClientsTo($starter->organization, 1);

        $this->demoClientIn($starter->organization);
        $this->demoClientIn($starter->organization);

        $live = $this->create([], $starter);

        $this->assertSame(RecordEnvironment::Live, $live->record_environment);
    }

    #[Test]
    public function inactive_and_archived_clients_free_their_place(): void
    {
        $starter = $this->createOrganization(plan: 'starter');
        $this->limitActiveClientsTo($starter->organization, 1);

        $this->inTenant($starter->organization, fn () => Client::factory()->status(ClientStatus::Inactive)->create());
        $this->inTenant($starter->organization, fn () => Client::factory()->status(ClientStatus::Archived)->create());

        $this->assertNotNull($this->create([], $starter));
    }

    #[Test]
    public function one_organizations_clients_do_not_use_up_anothers_limit(): void
    {
        $starter = $this->createOrganization(plan: 'starter');
        $this->limitActiveClientsTo($starter->organization, 1);

        $this->create([], $this->createOrganization());
        $this->create([], $this->createOrganization());

        $this->assertNotNull($this->create([], $starter));
    }

    #[Test]
    public function an_unlimited_plan_has_no_limit(): void
    {
        $enterprise = $this->createOrganization(plan: 'enterprise');

        $this->assertNull(app(EntitlementService::class)->limit($enterprise->organization, FeatureRegistry::MAX_ACTIVE_CLIENTS));
        $this->assertSame(3, $this->create(['first_name' => 'A'], $enterprise)->client_number + $this->create(['first_name' => 'B'], $enterprise)->client_number);
    }
}
