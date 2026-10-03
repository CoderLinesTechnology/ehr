<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\ChangeClientStatus;
use App\Domain\Clients\ClientDirectory;
use App\Domain\Clients\ClientFormOptions;
use App\Domain\Clients\ClientListFilters;
use App\Domain\Clients\ClientSearch;
use App\Domain\Clients\ClientStatus;
use App\Domain\Clients\ClientTimelineReader;
use App\Domain\Clients\ClientVisibility;
use App\Domain\Clients\CreateClient;
use App\Domain\Clients\DeleteClientContact;
use App\Domain\Clients\GlobalSearch;
use App\Domain\Clients\RecordClientAccess;
use App\Domain\Clients\SaveClientContact;
use App\Domain\Clients\UpdateClient;
use App\Domain\Platform\CreatedOrganization;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantMismatch;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;

/**
 * Organization A's people, acting in A, can reach nothing of organization B's
 * through any door the clients module has: listing, search, ids, policies,
 * every action, even when the same person also belongs to B.
 */
class ClientTenantIsolationTest extends ClientsTestCase
{
    private CreatedOrganization $a;

    private CreatedOrganization $b;

    /** A member of A who is ALSO a member of B: the tenant in use decides, not the person. */
    private OrganizationMembership $inA;

    private OrganizationMembership $inB;

    private OrganizationMembership $bClinician;

    private Client $mine;

    private Client $theirs;

    private ClientContact $theirContact;

    private Location $theirLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->createOrganization();
        $this->b = $this->createOrganization();

        $this->inA = $this->a->ownerMembership;
        $this->inB = $this->addStaff($this->b->organization, 'org_admin', user: User::query()->findOrFail($this->inA->user_id));
        $this->bClinician = $this->addStaff($this->b->organization, 'clinician', user: User::factory()->create(['name' => 'Bea Foreign', 'email' => 'bea@foreign.test']));

        $this->mine = $this->clientIn($this->a->organization, ['first_name' => 'Ama', 'last_name' => 'Mine', 'email' => 'ama@mine.test', 'phone' => '+233244100001']);

        // B's client is made the real way: audit, timeline, contact, appointment.
        $this->theirs = $this->actAs($this->inB, $this->b->organization, fn () => app(CreateClient::class)([
            'first_name' => 'Yaw', 'last_name' => 'Foreign', 'email' => 'yaw@foreign.test', 'phone' => '0551234567',
            'primary_clinician_membership_id' => $this->bClinician->id,
        ], User::query()->findOrFail($this->inB->user_id)));
        $this->theirContact = $this->actAs($this->inB, $this->b->organization, fn () => app(SaveClientContact::class)(
            $this->theirs, ['name' => 'Kofi Foreign', 'phone' => '0551234568'], null, User::query()->findOrFail($this->inB->user_id),
        ));
        $this->theirLocation = $this->inTenant($this->b->organization, fn () => Location::factory()->create());
        $this->bookAppointment($this->b->organization, $this->theirs, $this->bClinician);
    }

    /** Everything B owns that a stranger could read or damage, as one comparable value. */
    private function snapshotOfB(): string
    {
        $b = $this->b->organization->id;

        return json_encode([
            DB::table('clients')->where('organization_id', $b)->orderBy('id')->get(),
            DB::table('client_contacts')->where('organization_id', $b)->orderBy('id')->get(),
            DB::table('timeline_entries')->where('organization_id', $b)->orderBy('id')->get(),
            DB::table('appointments')->where('organization_id', $b)->orderBy('id')->get(),
            DB::table('audit_logs')->where('organization_id', $b)->orderBy('id')->get(),
            DB::table('organization_counters')->where('organization_id', $b)->orderBy('key')->get(),
        ], JSON_THROW_ON_ERROR);
    }

    /** Run as the person who belongs to both organizations, acting in A. */
    private function inOrganizationA(callable $callback): mixed
    {
        return $this->actAs($this->inA, $this->a->organization, $callback);
    }

    private function actor(): User
    {
        return User::query()->findOrFail($this->inA->user_id);
    }

    #[Test]
    public function the_client_list_never_shows_another_organizations_clients(): void
    {
        $directory = fn (array $query) => $this->inOrganizationA(fn () => app(ClientDirectory::class)
            ->query($this->inA, ClientListFilters::from($query))->get()->pluck('id')->all());

        $this->assertSame([$this->mine->id], $directory([]));
        $this->assertSame([], $directory(['clinician' => $this->bClinician->id]), 'filtering by their clinician');
        $this->assertSame([], $directory(['q' => 'foreign']));
        $this->assertSame([], $directory(['q' => '0551234567']));
        $this->assertSame([], $directory(['q' => 'yaw@foreign.test']));
        $this->assertSame([], $directory(['q' => 'C-00001', 'status' => 'all', 'records' => 'live', 'clinician' => $this->bClinician->id]));
    }

    #[Test]
    public function search_by_any_identifier_of_their_client_finds_nothing(): void
    {
        foreach (['Yaw Foreign', 'foreign', 'yaw@foreign.test', '0551234567', '+233 55 123 4567', '055 123', '+233551234567'] as $term) {
            $found = $this->inOrganizationA(fn () => app(ClientSearch::class)->find($this->inA, $term)->items->all());
            $this->assertSame([], $found, "searching [{$term}]");

            $global = $this->inOrganizationA(fn () => app(GlobalSearch::class)($this->inA, $term));
            $this->assertTrue($global->isEmpty(), "global search [{$term}]");
        }

        // Their client is number 1 in THEIR organization; so is ours. The number finds ours.
        $this->assertSame(1, $this->theirs->client_number);
        $this->assertSame([$this->mine->id], $this->inOrganizationA(fn () => app(ClientSearch::class)->find($this->inA, 'C-00001')->items->pluck('id')->all()));
        $this->assertSame([], $this->inOrganizationA(fn () => app(GlobalSearch::class)($this->inA, 'bea')->staff->items->all()), 'their staff either');
    }

    #[Test]
    public function visibility_and_the_policy_treat_their_client_as_not_there(): void
    {
        $this->assertFalse($this->inOrganizationA(fn () => ClientVisibility::allows($this->theirs, $this->inA)));
        $this->assertSame([$this->mine->id], $this->inOrganizationA(fn () => ClientVisibility::apply(Client::query(), $this->inA)->pluck('id')->all()));

        foreach (['view', 'update', 'archive'] as $ability) {
            $response = $this->inOrganizationA(fn () => Gate::forUser($this->actor())->inspect($ability, $this->theirs));
            $this->assertInstanceOf(Response::class, $response);
            $this->assertFalse($response->allowed(), $ability);
            $this->assertSame(404, $response->status(), $ability);
        }
        $this->assertSame(404, $this->inOrganizationA(fn () => Gate::forUser($this->actor())->inspect('changeStatus', [$this->theirs, ClientStatus::Archived]))->status());
    }

    #[Test]
    public function the_selects_offer_only_this_organizations_clinicians_and_locations(): void
    {
        $options = new ClientFormOptions;

        $clinicians = $this->inOrganizationA(fn () => $options->clinicians($this->bClinician->id));   // even when asked to include one of theirs
        $this->assertArrayNotHasKey($this->bClinician->id, $clinicians);
        $this->assertArrayHasKey($this->inA->id, $clinicians);

        $locations = $this->inOrganizationA(fn () => $options->locations($this->theirLocation->id));
        $this->assertArrayNotHasKey($this->theirLocation->id, $locations);
    }

    #[Test]
    public function every_action_refuses_their_records_and_changes_nothing(): void
    {
        $before = $this->snapshotOfB();
        $actor = $this->actor();

        $attempts = [
            'update the client' => fn () => app(UpdateClient::class)($this->theirs, ['first_name' => 'Hijacked', 'city' => 'Nowhere'], $actor),
            'archive the client' => fn () => app(ChangeClientStatus::class)($this->theirs, ClientStatus::Archived, $actor, 'Hijack.'),
            'add a contact' => fn () => app(SaveClientContact::class)($this->theirs, ['name' => 'Intruder'], null, $actor),
            'change their contact' => fn () => app(SaveClientContact::class)($this->theirs, ['name' => 'Hijacked'], $this->theirContact, $actor),
            'remove their contact' => fn () => app(DeleteClientContact::class)($this->theirs, $this->theirContact, $actor),
        ];

        foreach ($attempts as $what => $attempt) {
            try {
                $this->inOrganizationA($attempt);
                $this->fail("Must not be able to {$what} from another organization.");
            } catch (ModelNotFoundException) {
                // the tenant scope hides their rows
            }
        }

        foreach ([
            'read their timeline' => fn () => app(ClientTimelineReader::class)->page($this->theirs, $this->inA),
            'record a view of their client' => fn () => app(RecordClientAccess::class)($this->theirs, $actor, 'overview'),
        ] as $what => $attempt) {
            try {
                $this->inOrganizationA($attempt);
                $this->fail("Must not be able to {$what} from another organization.");
            } catch (TenantMismatch) {
                // refused outright
            }
        }

        $this->assertSame($before, $this->snapshotOfB(), 'not one row of B changed, and nothing was audited into B\'s trail');
    }

    #[Test]
    public function a_client_cannot_be_pointed_at_their_clinician_or_location(): void
    {
        $before = $this->snapshotOfB();
        $actor = $this->actor();

        foreach ([
            ['primary_clinician_membership_id', $this->bClinician->id],
            ['primary_location_id', $this->theirLocation->id],
        ] as [$field, $foreignId]) {
            try {
                $this->inOrganizationA(fn () => app(CreateClient::class)(['first_name' => 'Ama', 'last_name' => 'New', 'email' => 'new@mine.test', $field => $foreignId], $actor));
                $this->fail("Creating with a foreign {$field} must fail.");
            } catch (DomainException $e) {
                $this->assertSame($field, $e->field());
            }

            try {
                $this->inOrganizationA(fn () => app(UpdateClient::class)($this->mine, [$field => $foreignId], $actor));
                $this->fail("Updating with a foreign {$field} must fail.");
            } catch (DomainException $e) {
                $this->assertSame($field, $e->field());
            }
        }

        $this->assertNull(DB::table('clients')->where('id', $this->mine->id)->value('primary_clinician_membership_id'));
        $this->assertNull(DB::table('clients')->where('id', $this->mine->id)->value('primary_location_id'));
        $this->assertSame($before, $this->snapshotOfB());
        $this->assertSame(1, DB::table('clients')->where('organization_id', $this->a->organization->id)->count(), 'nothing was created in A either');
    }

    #[Test]
    public function the_same_person_acting_in_b_does_reach_bs_records_proving_the_tenant_in_use_decides(): void
    {
        $found = $this->actAs($this->inB, $this->b->organization, fn () => app(ClientSearch::class)->find($this->inB, 'foreign')->items->pluck('id')->all());
        $this->assertSame([$this->theirs->id], $found);

        $foundMine = $this->actAs($this->inB, $this->b->organization, fn () => app(ClientSearch::class)->find($this->inB, 'Mine')->items->all());
        $this->assertSame([], $foundMine, 'and from B, A\'s are out of reach');
    }
}
