<?php

namespace Tests\Feature\Clients\Http;

use App\Domain\Clients\SaveClientContact;
use App\Domain\Platform\CreatedOrganization;
use App\Domain\Scheduling\Modality;
use App\Models\Client;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Service;
use App\Models\User;
use App\Support\PhoneNumbers;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Clients\ClientsTestCase;

/**
 * The client model through real requests: the create form's types (adult with several e-mails and phones,
 * minor with a guardian, couple), the list's new columns and filters, the profile, virtual clients booking as
 * telehealth, the client pickers' JSON search, and "Add a new client" from New appointment.
 */
class ClientModelHttpTest extends ClientsTestCase
{
    private CreatedOrganization $a;

    private OrganizationMembership $manager;

    private OrganizationMembership $dr1;

    private OrganizationMembership $dr2;

    private OrganizationMembership $receptionist;

    private Client $dr1sClient;

    private Client $dr2sClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->createOrganization(['name' => 'Alpha Practice']);
        $org = $this->a->organization;
        $this->manager = $this->addStaff($org, 'practice_manager');
        $this->dr1 = $this->addStaff($org, 'clinician');
        $this->dr2 = $this->addStaff($org, 'clinician');
        $this->receptionist = $this->addStaff($org, 'receptionist');

        $this->dr1sClient = $this->clientIn($org, ['first_name' => 'Adaeze', 'last_name' => 'Okafor', 'primary_clinician_membership_id' => $this->dr1->id]);
        $this->dr2sClient = $this->clientIn($org, ['first_name' => 'Kwame', 'last_name' => 'Mensah', 'primary_clinician_membership_id' => $this->dr2->id]);
    }

    private function as(OrganizationMembership $member): static
    {
        $this->app->forgetScopedInstances();
        $this->actingAs(User::query()->findOrFail($member->user_id));

        return $this;
    }

    private function url(string $name, array $parameters = []): string
    {
        return route($name, ['organization' => $this->a->organization->slug] + $parameters);
    }

    private function find(string $first): Client
    {
        return $this->inTenant($this->a->organization, fn () => Client::query()->where('first_name', $first)->firstOrFail());
    }

    // ---- create -------------------------------------------------------------------------------------------------------

    #[Test]
    public function an_adult_is_added_with_two_emails_and_two_phones_and_the_profile_shows_them_all(): void
    {
        $this->as($this->receptionist)->post($this->url('app.clients.store'), [
            'client_type' => 'adult', 'first_name' => 'Efua', 'last_name' => 'Boateng', 'billing_type' => 'insurance',
            'emails' => ['0' => ['value' => 'efua@example.org', 'label' => 'home'], 's1' => ['value' => 'efua@work.example.org', 'label' => 'work']],
            'primary_email' => '0',
            'phones' => ['0' => ['value' => '024 410 0001', 'label' => 'mobile'], 's1' => ['value' => '030 212 3456', 'label' => 'work']],
            'primary_phone' => 's1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $client = $this->find('Efua');
        $this->assertSame(['efua@example.org', '+233302123456', 'insurance'], [$client->email, $client->phone, $client->billing_type->value]);
        $this->assertSame(4, DB::table('client_contact_points')->where('client_id', $client->id)->count());

        $this->as($this->receptionist)->get($this->url('app.clients.show', ['client' => $client->id]))->assertOk()
            ->assertSeeInOrder([PhoneNumbers::display('+233302123456', 'GH'), 'Work', PhoneNumbers::display('+233244100001', 'GH'), 'Mobile'])
            ->assertSee('efua@work.example.org')->assertSee('Insurance');
    }

    #[Test]
    public function invalid_rows_come_back_on_the_row_that_is_wrong(): void
    {
        $this->as($this->receptionist)->from($this->url('app.clients.create'))->post($this->url('app.clients.store'), [
            'first_name' => 'Efua', 'last_name' => 'Boateng',
            'emails' => [['value' => 'a@example.org'], ['value' => 'A@example.org'], ['value' => 'nope']],
            'phones' => [['value' => '12']],
        ])->assertRedirect($this->url('app.clients.create'))->assertSessionHasErrors(['emails.1.value', 'emails.2.value', 'phones.0.value']);

        $this->assertSame(2, DB::table('clients')->where('organization_id', $this->a->organization->id)->count());
    }

    #[Test]
    public function a_minor_needs_a_guardian_and_is_added_with_one(): void
    {
        $minor = ['client_type' => 'minor', 'first_name' => 'Kwesi', 'last_name' => 'Owusu', 'phones' => [['value' => '0244100002']]];

        $this->as($this->receptionist)->post($this->url('app.clients.store'), $minor + ['guardians' => [['name' => '', 'phone' => '']]])
            ->assertSessionHasErrors('guardians');
        $this->as($this->receptionist)->post($this->url('app.clients.store'), $minor + ['guardians' => [['name' => '', 'phone' => '0244100001']]])
            ->assertSessionHasErrors('guardians.0.name');

        $this->as($this->receptionist)->post($this->url('app.clients.store'), $minor + ['guardians' => [['name' => 'Akosua Owusu', 'relationship_type' => 'parent', 'phone' => '0244100001', 'is_emergency_contact' => '1']]])
            ->assertSessionHasNoErrors();

        $client = $this->find('Kwesi');
        $this->assertTrue($client->isMinor());
        $this->as($this->receptionist)->get($this->url('app.clients.contacts.index', ['client' => $client->id]))->assertOk()
            ->assertSee('Akosua Owusu')->assertSee('Parent or guardian')->assertSee('Emergency contact');
        $this->as($this->receptionist)->get($this->url('app.clients.index'))->assertSee('Guardian:')->assertSee('Akosua Owusu');
    }

    #[Test]
    public function a_couple_of_an_existing_and_a_new_client_is_linked_on_both_rows_of_the_list(): void
    {
        $this->as($this->manager)->post($this->url('app.clients.store'), [
            'client_type' => 'couple',
            'first_name' => 'ignored',
            'partners' => [
                '1' => ['mode' => 'existing', 'client_id' => $this->dr1sClient->id],
                '2' => ['mode' => 'new', 'first_name' => 'Chidi', 'last_name' => 'Okafor', 'phone' => '0244100003'],
            ],
        ])->assertSessionHasNoErrors();

        $couple = $this->inTenant($this->a->organization, fn () => Client::query()->where('client_type', 'couple')->firstOrFail());
        $this->assertSame('Adaeze & Chidi Okafor', $couple->displayName());

        $html = $this->as($this->manager)->get($this->url('app.clients.index'))->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'Couple:</span>'), 'both members link to the couple');
        $this->assertStringContainsString('Members:</span>', $html);
        $this->assertStringContainsString('href="'.$this->url('app.clients.show', ['client' => $couple->id]).'"', $html);
        $this->assertStringContainsString('class="type-tag type-tag--couple">Couple</span>', $html);

        // Its profile lists the members; a member's profile lists the couple.
        $this->as($this->manager)->get($this->url('app.clients.show', ['client' => $couple->id]))->assertOk()->assertSee('Couple members')->assertSee('Chidi Okafor');
        $this->as($this->manager)->get($this->url('app.clients.show', ['client' => $this->dr1sClient->id]))->assertOk()->assertSee('Adaeze &amp; Chidi Okafor', false);
    }

    #[Test]
    public function a_couple_needs_both_partners(): void
    {
        $this->as($this->manager)->post($this->url('app.clients.store'), [
            'client_type' => 'couple',
            'partners' => ['1' => ['mode' => 'existing', 'client_id' => ''], '2' => ['mode' => 'new', 'first_name' => '', 'last_name' => 'Okafor']],
        ])->assertSessionHasErrors(['partners.1.client_id', 'partners.2.first_name']);
    }

    #[Test]
    public function editing_shows_and_saves_the_new_fields(): void
    {
        $location = $this->inTenant($this->a->organization, fn () => Location::factory()->create(['name' => 'Harbour Clinic']));
        $edit = $this->url('app.clients.edit', ['client' => $this->dr1sClient->id]);

        $this->as($this->manager)->get($edit)->assertOk()->assertSee('Virtual (telehealth)')->assertSee('Harbour Clinic')->assertSee('name="client_type"', false);

        $this->as($this->manager)->put($this->url('app.clients.update', ['client' => $this->dr1sClient->id]), [
            'first_name' => 'Adaeze', 'last_name' => 'Okafor', 'client_type' => 'adult', 'billing_type' => 'insurance', 'primary_location_id' => 'virtual',
            'emails' => [['value' => 'adaeze@example.org']], 'phones' => [['value' => '0244100005'], ['value' => '0244100006', 'label' => 'work']], 'primary_phone' => '1',
        ])->assertSessionHasNoErrors();

        $client = $this->find('Adaeze');
        $this->assertSame([true, null, 'insurance', '+233244100006'], [$client->is_virtual, $client->primary_location_id, $client->billing_type->value, $client->phone]);
        $this->as($this->manager)->get($this->url('app.clients.show', ['client' => $client->id]))->assertSee('Virtual (telehealth)');
    }

    // ---- list -----------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_list_merges_contact_into_the_client_column_and_drops_the_client_number(): void
    {
        $html = $this->as($this->manager)->get($this->url('app.clients.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<th scope="col" class="col-rel">Relationship</th>', $html);
        $this->assertStringContainsString('<th scope="col" class="col-billing">Billing</th>', $html);
        $this->assertStringNotContainsString('class="col-contact"', $html);
        $this->assertStringNotContainsString($this->dr1sClient->formattedNumber(), $html, 'no client number in the table');
        $this->assertStringContainsString('Self pay', $html);
        $this->assertStringContainsString('Clinician:', $html);

        // Still searchable by number.
        $this->as($this->manager)->get($this->url('app.clients.index', ['q' => $this->dr2sClient->formattedNumber()]))
            ->assertSee('Kwame Mensah')->assertDontSee('Adaeze Okafor');
    }

    #[Test]
    public function the_list_filters_by_client_type_billing_and_virtual(): void
    {
        $org = $this->a->organization;
        $this->inTenant($org, fn () => Client::query()->whereKey($this->dr1sClient->id)->update(['billing_type' => 'insurance', 'is_virtual' => true, 'client_type' => 'minor']));
        $page = fn (array $query) => $this->as($this->manager)->get($this->url('app.clients.index', $query))->assertOk();

        $page(['billing' => 'insurance'])->assertSee('Adaeze Okafor')->assertDontSee('Kwame Mensah');
        $page(['billing' => 'self_pay'])->assertSee('Kwame Mensah')->assertDontSee('Adaeze Okafor');
        $page(['location' => 'virtual'])->assertSee('Adaeze Okafor')->assertDontSee('Kwame Mensah');
        $page(['type' => 'minor'])->assertSee('Adaeze Okafor')->assertDontSee('Kwame Mensah');
        $page(['type' => 'adult'])->assertSee('Kwame Mensah')->assertDontSee('Adaeze Okafor');
        $page(['type' => 'bogus', 'billing' => 'cash'])->assertSee('Kwame Mensah')->assertSee('Adaeze Okafor');
    }

    #[Test]
    public function the_records_filter_shows_only_while_demo_data_exists(): void
    {
        $this->as($this->manager)->get($this->url('app.clients.index'))->assertDontSee('All Records')->assertSee('All Client Types');

        $this->demoClientIn($this->a->organization, ['first_name' => 'Demo', 'last_name' => 'Person']);
        $this->as($this->manager)->get($this->url('app.clients.index'))->assertSee('All Records');
    }

    #[Test]
    public function the_list_and_its_relationships_cost_the_same_queries_for_two_rows_or_eight(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->as($this->manager)->get($this->url('app.clients.index'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $add = function () {
            foreach (range(1, 3) as $i) {
                $client = $this->clientIn($this->a->organization, ['primary_clinician_membership_id' => $this->dr1->id]);
                $this->inTenant($this->a->organization, fn () => app(SaveClientContact::class)($client, ['name' => 'Parent '.$i, 'relationship_type' => 'parent', 'phone' => '0244100001']));
            }
        };

        $count();
        $add();
        $few = $count();
        $add();
        $this->assertSame($few, $count(), 'adding rows must not add queries');
    }

    // ---- virtual → telehealth ---------------------------------------------------------------------------------------

    #[Test]
    public function a_virtual_client_books_as_telehealth_by_default(): void
    {
        $this->inTenant($this->a->organization, fn () => Client::query()->whereKey($this->dr1sClient->id)->update(['is_virtual' => true]));

        $this->as($this->manager)->get($this->url('app.clients.show', ['client' => $this->dr1sClient->id]))
            ->assertSee(e($this->url('app.appointments.create', ['client' => $this->dr1sClient->id, 'modality' => 'telehealth'])), false);
        $this->as($this->manager)->get($this->url('app.clients.index'))
            ->assertSee(e($this->url('app.appointments.create', ['client' => $this->dr1sClient->id, 'modality' => 'telehealth'])), false);

        $html = $this->as($this->manager)->get($this->url('app.appointments.create', ['client' => $this->dr1sClient->id]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<option value="telehealth"\s+selected#', $html);
        $this->assertStringNotContainsString('id="f-location"', $html, 'no location for telehealth');

        // In person stays possible when the booker chooses it.
        $html = $this->as($this->manager)->get($this->url('app.appointments.create', ['client' => $this->dr1sClient->id, 'modality' => 'in_person']))->getContent();
        $this->assertMatchesRegularExpression('#<option value="in_person"\s+selected#', $html);

        // A client who is not virtual gets no default.
        $html = $this->as($this->manager)->get($this->url('app.appointments.create', ['client' => $this->dr2sClient->id]))->getContent();
        $this->assertDoesNotMatchRegularExpression('#<option value="telehealth"\s+selected#', $html);
    }

    // ---- client picker search ----------------------------------------------------------------------------------------

    #[Test]
    public function the_search_answers_json_with_visible_clients_only(): void
    {
        $this->as($this->dr1)->getJson($this->url('app.search', ['q' => 'okafor']))->assertOk()
            ->assertExactJson(['clients' => [['id' => $this->dr1sClient->id, 'name' => 'Adaeze Okafor', 'number' => $this->dr1sClient->formattedNumber(), 'type' => 'adult', 'archived' => false]], 'more' => false]);
        $this->as($this->dr1)->getJson($this->url('app.search', ['q' => 'mensah']))->assertOk()->assertExactJson(['clients' => [], 'more' => false]);
        $this->as($this->dr1)->get($this->url('app.search', ['q' => 'okafor']))->assertOk()->assertSee('Adaeze Okafor')->assertHeader('content-type', 'text/html; charset=utf-8');
    }

    // ---- New appointment: add a new client without leaving ------------------------------------------------------------

    #[Test]
    public function new_appointment_offers_a_new_client_opened_and_prefilled_when_the_search_finds_nobody(): void
    {
        $html = $this->as($this->receptionist)->get($this->url('app.appointments.create', ['q' => 'Ama Serwaa']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<details class="card appt-card appt-newclient" id="new-client"\s+open#', $html);
        $this->assertStringContainsString('value="Ama"', $html);
        $this->assertStringContainsString('value="Serwaa"', $html);
        $this->assertStringContainsString('<input type="hidden" name="then" value="appointment">', $html);
        // Its own form, after the GET search form closed: never nested.
        $this->assertMatchesRegularExpression('#</form>\s*\{?\s*<details#', preg_replace('/<!--.*?-->/s', '', $html));

        // Something found: the disclosure is there but closed; a phone number is not a name.
        $html = $this->as($this->receptionist)->get($this->url('app.appointments.create', ['q' => 'okafor']))->getContent();
        $this->assertDoesNotMatchRegularExpression('#id="new-client"\s+open#', $html);
        $html = $this->as($this->receptionist)->get($this->url('app.appointments.create', ['q' => '024 999 9999']))->getContent();
        $this->assertMatchesRegularExpression('#id="new-client"\s+open#', $html);
        $this->assertStringNotContainsString('value="024"', $html);

        // Members who may not create clients do not see it.
        $billing = $this->addStaff($this->a->organization, 'billing');
        $this->as($billing)->get($this->url('app.appointments.create', ['q' => 'nobody']))->assertDontSee('Add a new client');
    }

    #[Test]
    public function a_client_added_from_new_appointment_comes_back_chosen_with_the_sanitised_choices(): void
    {
        $service = $this->inTenant($this->a->organization, fn () => Service::factory()->create());

        $response = $this->as($this->receptionist)->post($this->url('app.clients.store'), [
            'then' => 'appointment',
            'first_name' => 'Ama', 'last_name' => 'Serwaa', 'phones' => [['value' => '0244100009']],
            'carry' => [
                'service' => $service->id, 'clinician' => $this->dr1->id, 'modality' => 'telehealth', 'date' => '2026-11-03', 'time' => '09:30',
                'location' => 'javascript:alert(1)', 'extra' => 'https://evil.example',
            ],
        ])->assertSessionHasNoErrors();

        $client = $this->find('Ama');
        $response->assertRedirect($this->url('app.appointments.create', [
            'client' => $client->id, 'service' => $service->id, 'clinician' => $this->dr1->id, 'modality' => Modality::Telehealth->value, 'date' => '2026-11-03', 'time' => '09:30',
        ]));
        $this->assertSame($this->dr1->id, $client->primary_clinician_membership_id, 'the chosen clinician becomes the primary clinician');
        $this->assertStringNotContainsString('evil', $response->headers->get('Location'));
    }

    #[Test]
    public function validation_errors_return_to_new_appointment_with_the_form_open_and_the_input_kept(): void
    {
        $page = $this->url('app.appointments.create', ['q' => 'Ama Serwaa']);
        $this->as($this->receptionist)->get($page);

        $input = ['then' => 'appointment', 'first_name' => 'Ama', 'last_name' => '', 'phones' => [['value' => '12']]];
        $this->as($this->receptionist)->from($page)->post($this->url('app.clients.store'), $input)
            ->assertRedirect($page)->assertSessionHasErrors(['last_name', 'phones.0.value']);

        // Follow the redirect in one go (asserting on the session first re-starts it and empties the flashed bag).
        $html = $this->as($this->receptionist)->from($page)->followingRedirects()->post($this->url('app.clients.store'), $input)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#id="new-client"\s+open#', $html);
        $this->assertStringContainsString('Enter the client', $html);
        $this->assertStringContainsString('The client could not be added', $html);
        $this->assertStringContainsString('value="12"', $html);
    }

    #[Test]
    public function a_clinician_who_could_not_see_the_new_client_gets_a_message_not_a_dead_end(): void
    {
        // Their own provider membership is the default: they can book for the client they just added.
        $this->as($this->dr1)->post($this->url('app.clients.store'), ['then' => 'appointment', 'first_name' => 'Yaa', 'last_name' => 'Asantewaa', 'phones' => [['value' => '0244100011']]])
            ->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame($this->dr1->id, $this->find('Yaa')->primary_clinician_membership_id);

        // A clinician-role member who is not a provider, with no clinician chosen: the client is created but not theirs.
        $nonProvider = $this->addStaff($this->a->organization, 'clinician', ['is_provider' => false]);
        $this->as($nonProvider)->post($this->url('app.clients.store'), ['then' => 'appointment', 'first_name' => 'Abena', 'last_name' => 'Ofori', 'phones' => [['value' => '0244100012']], 'carry' => ['date' => '2026-11-03']])
            ->assertRedirect($this->url('app.appointments.create', ['date' => '2026-11-03']))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'not in your client list'));
    }
}
