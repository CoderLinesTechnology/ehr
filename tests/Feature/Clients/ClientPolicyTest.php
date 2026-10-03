<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\ClientStatus;
use App\Models\Client;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;

class ClientPolicyTest extends ClientsTestCase
{
    private $created;

    private OrganizationMembership $manager;

    private OrganizationMembership $dr1;

    private OrganizationMembership $dr2;

    private OrganizationMembership $receptionist;

    private OrganizationMembership $billing;

    private OrganizationMembership $staff;

    private Client $dr1sClient;

    private Client $dr2sClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();
        $org = $this->created->organization;

        $this->manager = $this->addStaff($org, 'practice_manager');
        $this->dr1 = $this->addStaff($org, 'clinician');
        $this->dr2 = $this->addStaff($org, 'clinician');
        $this->receptionist = $this->addStaff($org, 'receptionist');
        $this->billing = $this->addStaff($org, 'billing');
        $this->staff = $this->addStaff($org, 'staff');

        $this->dr1sClient = $this->clientIn($org, ['primary_clinician_membership_id' => $this->dr1->id]);
        $this->dr2sClient = $this->clientIn($org, ['primary_clinician_membership_id' => $this->dr2->id]);
    }

    /** @param array<int, mixed>|mixed $arguments */
    private function inspect(OrganizationMembership $member, string $ability, mixed $arguments = []): Response
    {
        return $this->actAs($member, $this->created->organization, function () use ($member, $ability, $arguments) {
            return Gate::forUser(User::query()->findOrFail($member->user_id))->inspect($ability, $arguments);
        });
    }

    private function assertAllowed(Response $response, string $message = ''): void
    {
        $this->assertTrue($response->allowed(), $message ?: 'expected the ability to be granted');
    }

    /** 403: they hold no such permission. */
    private function assertForbidden(Response $response, string $message = ''): void
    {
        $this->assertFalse($response->allowed(), $message);
        $this->assertNotSame(404, $response->status(), $message.' (must be a 403, not "not found")');
    }

    /** 404: the record is outside what they may see; existence is not revealed. */
    private function assertNotFound(Response $response, string $message = ''): void
    {
        $this->assertFalse($response->allowed(), $message);
        $this->assertSame(404, $response->status(), $message);
    }

    #[Test]
    public function the_organization_administrator_may_do_everything(): void
    {
        $owner = $this->created->ownerMembership;

        $this->assertAllowed($this->inspect($owner, 'viewAny', Client::class));
        $this->assertAllowed($this->inspect($owner, 'create', Client::class));
        foreach (['view', 'update', 'archive'] as $ability) {
            $this->assertAllowed($this->inspect($owner, $ability, $this->dr2sClient), $ability);
        }
        $this->assertAllowed($this->inspect($owner, 'changeStatus', [$this->dr2sClient, ClientStatus::Archived]));
    }

    #[Test]
    public function a_clinician_works_with_their_own_clients_and_does_not_learn_that_others_exist(): void
    {
        $this->assertAllowed($this->inspect($this->dr1, 'viewAny', Client::class));
        $this->assertAllowed($this->inspect($this->dr1, 'create', Client::class));
        $this->assertAllowed($this->inspect($this->dr1, 'view', $this->dr1sClient));
        $this->assertAllowed($this->inspect($this->dr1, 'update', $this->dr1sClient));

        // Someone else's client: not found, whatever is asked.
        $this->assertNotFound($this->inspect($this->dr1, 'view', $this->dr2sClient), 'view');
        $this->assertNotFound($this->inspect($this->dr1, 'update', $this->dr2sClient), 'update');
        $this->assertNotFound($this->inspect($this->dr1, 'archive', $this->dr2sClient), 'archive');
    }

    #[Test]
    public function an_appointment_makes_a_client_viewable_and_editable_by_the_clinician(): void
    {
        $this->assertNotFound($this->inspect($this->dr1, 'view', $this->dr2sClient));

        $this->bookAppointment($this->created->organization, $this->dr2sClient, $this->dr1);

        $this->assertAllowed($this->inspect($this->dr1, 'view', $this->dr2sClient));
        $this->assertAllowed($this->inspect($this->dr1, 'update', $this->dr2sClient));
    }

    #[Test]
    public function archiving_needs_its_own_permission_and_is_a_403_for_someone_who_can_see_the_client(): void
    {
        // A clinician sees their client but may not archive it: they know it exists, so: forbidden.
        $this->assertForbidden($this->inspect($this->dr1, 'archive', $this->dr1sClient));
        $this->assertForbidden($this->inspect($this->receptionist, 'archive', $this->dr1sClient));

        $this->assertAllowed($this->inspect($this->manager, 'archive', $this->dr1sClient));
    }

    #[Test]
    public function the_status_check_asks_for_archive_only_when_archiving_or_restoring(): void
    {
        $archivedClient = $this->inTenant($this->created->organization, fn () => Client::factory()->status(ClientStatus::Archived)->create(['primary_clinician_membership_id' => $this->dr1->id]));

        // active ⇄ inactive is an edit
        $this->assertAllowed($this->inspect($this->dr1, 'changeStatus', [$this->dr1sClient, ClientStatus::Inactive]));
        $this->assertAllowed($this->inspect($this->dr1, 'changeStatus', [$this->dr1sClient, ClientStatus::Active]));

        // to archived, and out of archived, is an archive
        $this->assertForbidden($this->inspect($this->dr1, 'changeStatus', [$this->dr1sClient, ClientStatus::Archived]));
        $this->assertForbidden($this->inspect($this->dr1, 'changeStatus', [$archivedClient, ClientStatus::Active]));

        $this->assertAllowed($this->inspect($this->manager, 'changeStatus', [$this->dr1sClient, ClientStatus::Archived]));
        $this->assertAllowed($this->inspect($this->manager, 'changeStatus', [$archivedClient, ClientStatus::Active]));

        // and never for a client outside their view
        $this->assertNotFound($this->inspect($this->dr2, 'changeStatus', [$this->dr1sClient, ClientStatus::Inactive]));
    }

    #[Test]
    public function reception_sees_everyone_and_registers_and_edits_but_does_not_archive(): void
    {
        $this->assertAllowed($this->inspect($this->receptionist, 'view', $this->dr2sClient));
        $this->assertAllowed($this->inspect($this->receptionist, 'create', Client::class));
        $this->assertAllowed($this->inspect($this->receptionist, 'update', $this->dr2sClient));
    }

    #[Test]
    public function billing_can_see_but_not_register_or_edit(): void
    {
        $this->assertAllowed($this->inspect($this->billing, 'viewAny', Client::class));
        $this->assertAllowed($this->inspect($this->billing, 'view', $this->dr1sClient));
        $this->assertForbidden($this->inspect($this->billing, 'create', Client::class));
        $this->assertForbidden($this->inspect($this->billing, 'update', $this->dr1sClient));
        $this->assertForbidden($this->inspect($this->billing, 'archive', $this->dr1sClient));
    }

    #[Test]
    public function staff_without_client_permissions_are_forbidden_not_told_about_records(): void
    {
        $this->assertForbidden($this->inspect($this->staff, 'viewAny', Client::class));
        $this->assertForbidden($this->inspect($this->staff, 'view', $this->dr1sClient));
        $this->assertForbidden($this->inspect($this->staff, 'create', Client::class));

        // Editing something they cannot even see is "not found", never a 403 that confirms the record exists.
        $this->assertNotFound($this->inspect($this->staff, 'update', $this->dr1sClient));
        $this->assertNotFound($this->inspect($this->staff, 'archive', $this->dr1sClient));
    }

    #[Test]
    public function a_permission_revoked_from_the_role_closes_the_ability_at_once(): void
    {
        $this->assertAllowed($this->inspect($this->dr1, 'update', $this->dr1sClient));
        $this->assertAllowed($this->inspect($this->dr1, 'create', Client::class));

        $this->revokeFromRole($this->created->organization, 'clinician', 'clients.edit');
        $this->revokeFromRole($this->created->organization, 'clinician', 'clients.create');

        $this->assertForbidden($this->inspect($this->dr1, 'update', $this->dr1sClient));
        $this->assertForbidden($this->inspect($this->dr1, 'create', Client::class));
        $this->assertAllowed($this->inspect($this->dr1, 'view', $this->dr1sClient), 'viewing is a separate permission');
    }

    #[Test]
    public function a_client_of_another_organization_is_not_found_for_everyone_including_the_administrator(): void
    {
        $other = $this->createOrganization();
        $foreign = $this->clientIn($other->organization);

        foreach (['view', 'update', 'archive'] as $ability) {
            $this->assertNotFound($this->inspect($this->created->ownerMembership, $ability, $foreign), $ability.' by the administrator');
            $this->assertNotFound($this->inspect($this->receptionist, $ability, $foreign), $ability.' by reception');
        }
    }

    #[Test]
    public function without_an_active_membership_in_the_current_tenant_everything_is_not_found(): void
    {
        $user = User::query()->findOrFail($this->dr1->user_id);

        // no tenant context at all
        $this->assertNotFound(Gate::forUser($user)->inspect('view', $this->dr1sClient));
        $this->assertNotFound(Gate::forUser($user)->inspect('viewAny', Client::class));
        $this->assertNotFound(Gate::forUser($user)->inspect('create', Client::class));

        // a tenant context acting as somebody else's membership
        $this->inTenant($this->created->organization, function () use ($user) {
            $this->assertNotFound(Gate::forUser($user)->inspect('view', $this->dr1sClient));
        }, $this->dr2);
    }
}
