<?php

namespace Tests\Feature\Clients\Http;

use App\Domain\Clients\ClientStatus;
use App\Domain\Clients\SaveClientContact;
use App\Domain\Platform\CreatedOrganization;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Clients\ClientsTestCase;

/**
 * Every client route through real requests: who may open it, 404 for what a member may not see,
 * tenant isolation by URL, validation, status reasons, the bulk action, search and the list's query bound.
 */
class ClientsHttpTest extends ClientsTestCase
{
    private CreatedOrganization $a;

    private CreatedOrganization $b;

    private OrganizationMembership $manager;

    private OrganizationMembership $dr1;

    private OrganizationMembership $dr2;

    private OrganizationMembership $receptionist;

    private OrganizationMembership $billing;

    private OrganizationMembership $staff;

    private Client $dr1sClient;

    private Client $dr2sClient;

    private Client $bClient;

    private ClientContact $bContact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->createOrganization(['name' => 'Alpha Practice']);
        $this->b = $this->createOrganization(['name' => 'Beta Practice']);
        $org = $this->a->organization;

        $this->manager = $this->addStaff($org, 'practice_manager');
        $this->dr1 = $this->addStaff($org, 'clinician');
        $this->dr2 = $this->addStaff($org, 'clinician');
        $this->receptionist = $this->addStaff($org, 'receptionist');
        $this->billing = $this->addStaff($org, 'billing');
        $this->staff = $this->addStaff($org, 'staff');

        $this->dr1sClient = $this->clientIn($org, ['first_name' => 'Adaeze', 'last_name' => 'Okafor', 'primary_clinician_membership_id' => $this->dr1->id]);
        $this->dr2sClient = $this->clientIn($org, ['first_name' => 'Kwame', 'last_name' => 'Mensah', 'primary_clinician_membership_id' => $this->dr2->id]);
        $this->bClient = $this->clientIn($this->b->organization, ['first_name' => 'Beatrice', 'last_name' => 'Outsider']);
        $this->bContact = $this->inTenant($this->b->organization, fn () => app(SaveClientContact::class)($this->bClient, ['name' => 'Their Contact', 'phone' => '+233241112222']));
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------

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

    private function owner(): OrganizationMembership
    {
        return $this->a->ownerMembership;
    }

    private function newClientInput(array $overrides = []): array
    {
        return $overrides + ['first_name' => 'Efua', 'last_name' => 'Boateng', 'phone' => '024 410 0001', 'email' => 'Efua@Example.com'];
    }

    // ---- list ---------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_list_is_for_members_who_may_view_clients_and_redirects_guests(): void
    {
        $this->get($this->url('app.clients.index'))->assertRedirect();

        foreach ([$this->owner(), $this->manager, $this->receptionist, $this->billing, $this->dr1] as $member) {
            $this->as($member)->get($this->url('app.clients.index'))->assertOk()->assertSee('Manage your clients and view their information.');
        }

        $this->as($this->staff)->get($this->url('app.clients.index'))->assertForbidden();
    }

    #[Test]
    public function the_list_shows_only_what_the_member_may_see(): void
    {
        $this->as($this->dr1)->get($this->url('app.clients.index'))
            ->assertSee('Adaeze Okafor')->assertDontSee('Kwame Mensah')->assertDontSee('Beatrice');

        $this->as($this->manager)->get($this->url('app.clients.index'))
            ->assertSee('Adaeze Okafor')->assertSee('Kwame Mensah')->assertDontSee('Beatrice Outsider');
    }

    #[Test]
    public function the_stat_cards_count_visible_live_clients_only(): void
    {
        $this->demoClientIn($this->a->organization, ['first_name' => 'Demo', 'last_name' => 'Person']);

        $html = $this->as($this->manager)->get($this->url('app.clients.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#Total Clients</span>\s*<span class="stat__value">2</span>#', $html);
        $this->assertMatchesRegularExpression('#New Clients \(30 days\)</span>\s*<span class="stat__value">2</span>#', $html);

        // A clinician's own card counts only their clients.
        $html = $this->as($this->dr1)->get($this->url('app.clients.index'))->getContent();
        $this->assertMatchesRegularExpression('#Total Clients</span>\s*<span class="stat__value">1</span>#', $html);
    }

    #[Test]
    public function the_list_filters_by_status_mine_location_and_record_type(): void
    {
        $org = $this->a->organization;
        $location = $this->inTenant($org, fn () => Location::factory()->create(['name' => 'Harbour Clinic']));
        $this->inTenant($org, fn () => Client::query()->whereKey($this->dr1sClient->id)->update(['primary_location_id' => $location->id]));
        $this->inTenant($org, fn () => Client::query()->whereKey($this->dr2sClient->id)->update(['status' => 'inactive']));
        $this->demoClientIn($org, ['first_name' => 'Demo', 'last_name' => 'Person']);

        $page = fn (array $query) => $this->as($this->manager)->get($this->url('app.clients.index', $query))->assertOk();

        $page(['status' => 'inactive'])->assertSee('Kwame Mensah')->assertDontSee('Adaeze Okafor');
        $page(['location' => $location->id])->assertSee('Adaeze Okafor')->assertDontSee('Kwame Mensah');
        $page(['records' => 'demo'])->assertSee('Demo Person')->assertDontSee('Adaeze Okafor');
        $page(['records' => 'live'])->assertDontSee('Demo Person')->assertSee('Adaeze Okafor');
        $page(['q' => 'mensah'])->assertSee('Kwame Mensah')->assertDontSee('Adaeze Okafor');
        $page(['location' => 'not-a-uuid', 'from' => 'garbage'])->assertOk()->assertSee('Adaeze Okafor');

        $this->as($this->dr1)->get($this->url('app.clients.index', ['mine' => '1']))->assertSee('Adaeze Okafor');
        $this->as($this->manager)->get($this->url('app.clients.index', ['mine' => '1']))->assertSee('No clients match');
    }

    #[Test]
    public function the_list_shows_next_appointment_and_last_visit_and_marks_demo_clients(): void
    {
        $org = $this->a->organization;
        $this->travelTo('2026-03-01 09:00:00');
        $this->bookAppointment($org, $this->dr1sClient, $this->dr1, 'scheduled');   // 2026-03-02 10:00 UTC (slot 1)
        $this->demoClientIn($org, ['first_name' => 'Demo', 'last_name' => 'Person']);

        $html = $this->as($this->manager)->get($this->url('app.clients.index'))->assertOk()->getContent();

        $this->assertStringContainsString('02/03/2026', strip_tags($html));
        $this->assertStringContainsString('class="badge badge--demo', $html);

        $this->travelBack();
    }

    #[Test]
    public function the_list_costs_the_same_number_of_queries_for_two_rows_or_eight(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->as($this->manager)->get($this->url('app.clients.index'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $add = function (int $n): void {
            foreach (range(1, $n) as $i) {
                $client = $this->clientIn($this->a->organization, ['primary_clinician_membership_id' => $this->dr1->id]);
                $this->bookAppointment($this->a->organization, $client, $this->dr1, $i % 2 ? 'completed' : 'scheduled');
            }
        };

        $count();
        $add(3);
        $two = $count();
        $add(3);
        $eight = $count();

        $this->assertSame($two, $eight, 'adding rows must not add queries');
        $this->assertLessThanOrEqual(45, $eight, 'a list page is a bounded number of statements');
    }

    // ---- profile ------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_profile_and_its_tabs_open_for_a_visible_client_and_record_the_access_once(): void
    {
        foreach (['app.clients.show', 'app.clients.timeline', 'app.clients.contacts.index', 'app.clients.appointments'] as $route) {
            $this->as($this->dr1)->get($this->url($route, ['client' => $this->dr1sClient->id]))->assertOk()->assertSee('Adaeze Okafor');
        }

        // Four tabs in one window: one entry (RecordClientAccess de-duplicates per user, client and ten minutes).
        $this->assertCount(1, $this->auditEntries('client.viewed')->where('subject_id', $this->dr1sClient->id));
    }

    #[Test]
    public function the_profile_of_a_client_outside_visibility_is_a_404_on_every_route(): void
    {
        $theirs = ['client' => $this->dr2sClient->id];

        foreach (['app.clients.show', 'app.clients.edit', 'app.clients.timeline', 'app.clients.contacts.index', 'app.clients.appointments'] as $route) {
            $this->as($this->dr1)->get($this->url($route, $theirs))->assertNotFound();
        }
        $this->as($this->dr1)->put($this->url('app.clients.update', $theirs), $this->newClientInput())->assertNotFound();
        $this->as($this->dr1)->post($this->url('app.clients.status', $theirs), ['status' => 'inactive', 'reason' => 'x'])->assertNotFound();
        $this->as($this->dr1)->post($this->url('app.clients.contacts.store', $theirs), ['name' => 'X'])->assertNotFound();
    }

    #[Test]
    public function another_organizations_clients_cannot_be_reached_by_url_even_by_an_administrator(): void
    {
        $theirs = ['client' => $this->bClient->id];
        $contact = $theirs + ['contact' => $this->bContact->id];

        foreach (['app.clients.show', 'app.clients.edit', 'app.clients.timeline', 'app.clients.contacts.index', 'app.clients.appointments'] as $route) {
            $this->as($this->owner())->get($this->url($route, $theirs))->assertNotFound();
        }
        $this->as($this->owner())->get($this->url('app.clients.contacts.edit', $contact))->assertNotFound();
        $this->as($this->owner())->put($this->url('app.clients.update', $theirs), $this->newClientInput())->assertNotFound();
        $this->as($this->owner())->post($this->url('app.clients.status', $theirs), ['status' => 'inactive', 'reason' => 'x'])->assertNotFound();
        $this->as($this->owner())->delete($this->url('app.clients.contacts.destroy', $contact))->assertNotFound();

        $this->assertSame('Beatrice', $this->inTenant($this->b->organization, fn () => Client::query()->findOrFail($this->bClient->id)->first_name));
        $this->assertSame(1, $this->inTenant($this->b->organization, fn () => ClientContact::query()->count()));
    }

    #[Test]
    public function a_contact_is_only_reachable_through_its_own_client(): void
    {
        $contact = $this->inTenant($this->a->organization, fn () => app(SaveClientContact::class)($this->dr1sClient, ['name' => 'Mother']));
        $this->inTenant($this->a->organization, fn () => app(SaveClientContact::class)($this->dr2sClient, ['name' => 'Father']));

        $this->as($this->manager)->get($this->url('app.clients.contacts.edit', ['client' => $this->dr2sClient->id, 'contact' => $contact->id]))->assertNotFound();
        $this->as($this->manager)->get($this->url('app.clients.contacts.edit', ['client' => $this->dr1sClient->id, 'contact' => $contact->id]))->assertOk();
    }

    // ---- create / edit --------------------------------------------------------------------------------------------------

    #[Test]
    public function creating_a_client_needs_the_create_permission(): void
    {
        foreach ([$this->billing, $this->staff] as $member) {
            $this->as($member)->get($this->url('app.clients.create'))->assertForbidden();
            $this->as($member)->post($this->url('app.clients.store'), $this->newClientInput())->assertForbidden();
        }

        foreach ([$this->receptionist, $this->dr1, $this->manager] as $member) {
            $this->as($member)->get($this->url('app.clients.create'))->assertOk()->assertSee('Add client');
        }
    }

    #[Test]
    public function a_new_client_is_validated_normalised_and_shown(): void
    {
        $this->as($this->receptionist)->post($this->url('app.clients.store'), ['first_name' => '', 'last_name' => '', 'phone' => '123', 'email' => 'nope'])
            ->assertSessionHasErrors(['first_name', 'last_name', 'phone', 'email']);

        $response = $this->as($this->receptionist)->post($this->url('app.clients.store'), $this->newClientInput())->assertSessionHasNoErrors();

        $client = $this->inTenant($this->a->organization, fn () => Client::query()->where('last_name', 'Boateng')->firstOrFail());
        $response->assertRedirect($this->url('app.clients.show', ['client' => $client->id]));
        $this->assertSame('+233244100001', $client->phone);
        $this->assertSame('efua@example.com', $client->email);
        $this->assertSame('live', $client->record_environment->value);
    }

    #[Test]
    public function status_and_environment_cannot_be_forced_through_the_form(): void
    {
        $this->as($this->receptionist)->post($this->url('app.clients.store'), $this->newClientInput(['status' => 'archived', 'record_environment' => 'demo', 'client_number' => 99999, 'organization_id' => $this->b->organization->id]));

        $client = $this->inTenant($this->a->organization, fn () => Client::query()->where('last_name', 'Boateng')->firstOrFail());
        $this->assertSame('live', $client->record_environment->value);
        $this->assertNotSame('archived', $client->status->value);
        $this->assertNotSame(99999, $client->client_number);
        $this->assertSame($this->a->organization->id, $client->organization_id);
    }

    #[Test]
    public function editing_a_client_prefills_the_form_and_saves_changes(): void
    {
        $this->as($this->manager)->get($this->url('app.clients.edit', ['client' => $this->dr1sClient->id]))
            ->assertOk()->assertSee('value="Adaeze"', false);

        $this->as($this->manager)->put($this->url('app.clients.update', ['client' => $this->dr1sClient->id]), ['first_name' => '', 'last_name' => 'Okafor'])
            ->assertSessionHasErrors('first_name');

        $this->as($this->manager)->put($this->url('app.clients.update', ['client' => $this->dr1sClient->id]), ['first_name' => 'Adaora', 'last_name' => 'Okafor', 'phone' => '+233 20 111 2233'])
            ->assertRedirect($this->url('app.clients.show', ['client' => $this->dr1sClient->id]));

        $fresh = $this->inTenant($this->a->organization, fn () => Client::query()->findOrFail($this->dr1sClient->id));
        $this->assertSame('Adaora', $fresh->first_name);
        $this->assertSame('+233201112233', $fresh->phone);

        // billing may see but not edit
        $this->as($this->billing)->get($this->url('app.clients.edit', ['client' => $this->dr1sClient->id]))->assertForbidden();
        $this->as($this->billing)->put($this->url('app.clients.update', ['client' => $this->dr1sClient->id]), ['first_name' => 'X', 'last_name' => 'Y'])->assertForbidden();
    }

    // ---- status ---------------------------------------------------------------------------------------------------------

    #[Test]
    public function marking_inactive_or_archiving_needs_a_reason_and_the_right_permission(): void
    {
        $route = $this->url('app.clients.status', ['client' => $this->dr1sClient->id]);

        $this->as($this->manager)->post($route, ['status' => 'inactive'])->assertSessionHasErrors('reason', errorBag: 'status_inactive');
        $this->as($this->manager)->post($route, ['status' => 'inactive', 'reason' => 'Moved away'])->assertRedirect();
        $this->assertSame('inactive', $this->inTenant($this->a->organization, fn () => Client::query()->findOrFail($this->dr1sClient->id)->status->value));

        // A clinician may edit but not archive.
        $this->as($this->dr1)->post($route, ['status' => 'archived', 'reason' => 'x'])->assertForbidden();
        $this->as($this->manager)->post($route, ['status' => 'archived'])->assertSessionHasErrors('reason', errorBag: 'status_archived');
        $this->as($this->manager)->post($route, ['status' => 'archived', 'reason' => 'Duplicate record'])->assertRedirect();
        $this->assertSame(ClientStatus::Archived, $this->inTenant($this->a->organization, fn () => Client::query()->findOrFail($this->dr1sClient->id)->status));

        $this->as($this->manager)->post($route, ['status' => 'active'])->assertRedirect();
        $this->assertSame(ClientStatus::Active, $this->inTenant($this->a->organization, fn () => Client::query()->findOrFail($this->dr1sClient->id)->status));

        $this->as($this->manager)->post($route, ['status' => 'bogus'])->assertSessionHasErrors('status');
    }

    #[Test]
    public function the_profile_offers_only_the_status_moves_the_member_may_make(): void
    {
        $page = fn (OrganizationMembership $m) => $this->as($m)->get($this->url('app.clients.show', ['client' => $this->dr1sClient->id]))->assertOk();

        $page($this->manager)->assertSee('Mark inactive')->assertSee('Archive');
        $page($this->dr1)->assertSee('Mark inactive')->assertDontSee('Archive client');
        $page($this->billing)->assertDontSee('Mark inactive')->assertDontSee('Archive');
    }

    #[Test]
    public function the_bulk_action_marks_only_visible_editable_clients_inactive(): void
    {
        $ids = [$this->dr1sClient->id, $this->dr2sClient->id, $this->bClient->id];

        // A clinician: own client changes; another clinician's and another organization's are silently skipped.
        $this->as($this->dr1)->post($this->url('app.clients.bulk-status'), ['clients' => $ids, 'reason' => 'Discharged'])->assertRedirect()->assertSessionHas('success');

        $status = fn (string $id, $org) => $this->inTenant($org, fn () => Client::query()->findOrFail($id)->status->value);
        $this->assertSame('inactive', $status($this->dr1sClient->id, $this->a->organization));
        $this->assertSame('active', $status($this->dr2sClient->id, $this->a->organization));
        $this->assertSame('active', $status($this->bClient->id, $this->b->organization));

        $this->as($this->dr1)->post($this->url('app.clients.bulk-status'), ['clients' => [$this->dr1sClient->id]])->assertSessionHasErrors('reason');
        $this->as($this->dr1)->post($this->url('app.clients.bulk-status'), ['clients' => [], 'reason' => 'x'])->assertSessionHasErrors('clients');
        $this->as($this->dr1)->post($this->url('app.clients.bulk-status'), ['clients' => ['nope'], 'reason' => 'x'])->assertSessionHasErrors('clients.0');

        // Billing can see clients but may not edit them: nothing changes.
        $this->as($this->billing)->post($this->url('app.clients.bulk-status'), ['clients' => [$this->dr2sClient->id], 'reason' => 'x'])->assertSessionHas('error');
        $this->assertSame('active', $status($this->dr2sClient->id, $this->a->organization));
        $this->as($this->staff)->post($this->url('app.clients.bulk-status'), ['clients' => [$this->dr2sClient->id], 'reason' => 'x'])->assertForbidden();
    }

    // ---- contacts -------------------------------------------------------------------------------------------------------

    #[Test]
    public function contacts_can_be_added_edited_and_removed_by_those_who_may_edit(): void
    {
        $base = ['client' => $this->dr1sClient->id];

        $this->as($this->manager)->post($this->url('app.clients.contacts.store', $base), ['name' => ''])->assertSessionHasErrors('name');
        $this->as($this->manager)->post($this->url('app.clients.contacts.store', $base), ['name' => 'Ngozi', 'relationship' => 'Mother', 'phone' => '024 111 2222', 'is_emergency_contact' => '1'])
            ->assertRedirect($this->url('app.clients.contacts.index', $base));

        $contact = $this->inTenant($this->a->organization, fn () => ClientContact::query()->where('name', 'Ngozi')->firstOrFail());
        $this->assertSame('+233241112222', $contact->phone);
        $this->as($this->manager)->get($this->url('app.clients.contacts.index', $base))->assertSee('Ngozi')->assertSee('Emergency contact');

        $this->as($this->manager)->put($this->url('app.clients.contacts.update', $base + ['contact' => $contact->id]), ['name' => 'Ngozi O.'])->assertRedirect();
        $this->assertSame('Ngozi O.', $this->inTenant($this->a->organization, fn () => ClientContact::query()->findOrFail($contact->id)->name));

        // View-only members cannot change contacts.
        $this->as($this->billing)->post($this->url('app.clients.contacts.store', $base), ['name' => 'Nope'])->assertForbidden();
        $this->as($this->billing)->delete($this->url('app.clients.contacts.destroy', $base + ['contact' => $contact->id]))->assertForbidden();

        $this->as($this->manager)->delete($this->url('app.clients.contacts.destroy', $base + ['contact' => $contact->id]))->assertRedirect();
        $this->assertSame(0, $this->inTenant($this->a->organization, fn () => ClientContact::query()->count()));
    }

    // ---- appointments / timeline ----------------------------------------------------------------------------------------

    #[Test]
    public function the_appointments_tab_needs_an_appointments_permission(): void
    {
        $this->as($this->billing)->get($this->url('app.clients.appointments', ['client' => $this->dr1sClient->id]))->assertOk();
        $this->as($this->dr1)->get($this->url('app.clients.appointments', ['client' => $this->dr1sClient->id]))->assertOk()->assertSee('You see the appointments you are the clinician on');
    }

    #[Test]
    public function the_timeline_tab_pages_with_a_cursor_and_ignores_a_bad_one(): void
    {
        $this->as($this->manager)->get($this->url('app.clients.timeline', ['client' => $this->dr1sClient->id]))->assertOk();
        $this->as($this->manager)->get($this->url('app.clients.timeline', ['client' => $this->dr1sClient->id, 'before' => '%%%garbage']))->assertOk();
    }

    // ---- search ---------------------------------------------------------------------------------------------------------

    #[Test]
    public function global_search_finds_visible_clients_and_staff_sections_follow_permissions(): void
    {
        $this->as($this->dr1)->get($this->url('app.search', ['q' => 'okafor']))->assertOk()->assertSee('Adaeze Okafor');
        $this->as($this->dr1)->get($this->url('app.search', ['q' => 'mensah']))->assertOk()->assertDontSee('Kwame Mensah')->assertSee('No clients found');
        $this->as($this->manager)->get($this->url('app.search', ['q' => 'mensah']))->assertSee('Kwame Mensah');
        $this->as($this->manager)->get($this->url('app.search', ['q' => 'outsider']))->assertDontSee('Beatrice Outsider');

        $this->as($this->manager)->get($this->url('app.search'))->assertOk()->assertSee('Start typing to search');
        $this->as($this->staff)->get($this->url('app.search', ['q' => 'okafor']))->assertOk()->assertDontSee('Adaeze Okafor');

        // Staff section: team.view only.
        $staffName = $this->dr2->user->name;
        $shown = $this->dr2->displayName();
        $this->as($this->billing)->get($this->url('app.search', ['q' => $staffName]))->assertOk()->assertSee('Staff')->assertSee($shown);
    }

    #[Test]
    public function search_treats_wildcards_and_markup_as_plain_text(): void
    {
        $this->as($this->manager)->get($this->url('app.search', ['q' => '%']))->assertOk()->assertSee('No clients found');
        $this->as($this->manager)->get($this->url('app.search', ['q' => '<script>alert(1)</script>']))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
        $this->as($this->manager)->get($this->url('app.clients.index', ['q' => "'; drop table clients; --"]))->assertOk();
        $this->assertSame(2, $this->inTenant($this->a->organization, fn () => Client::query()->count()));
    }
}
