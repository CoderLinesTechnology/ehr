<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\ClientVisibility;
use App\Models\Client;
use App\Models\OrganizationMembership;
use PHPUnit\Framework\Attributes\Test;

class ClientVisibilityTest extends ClientsTestCase
{
    private $created;

    private OrganizationMembership $dr1;

    private OrganizationMembership $dr2;

    private OrganizationMembership $supervisor;

    private OrganizationMembership $nobody;

    /** @var array<string, Client> */
    private array $c = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();
        $org = $this->created->organization;

        $this->dr1 = $this->addStaff($org, 'clinician');
        $this->dr2 = $this->addStaff($org, 'clinician');
        $this->supervisor = $this->addStaff($org, 'supervisor');
        $this->nobody = $this->addStaff($org, 'staff');

        $this->c['own'] = $this->clientIn($org, ['primary_clinician_membership_id' => $this->dr1->id]);
        $this->c['seen_by_dr1'] = $this->clientIn($org, ['primary_clinician_membership_id' => $this->dr2->id]);
        $this->c['cancelled_with_dr1'] = $this->clientIn($org, ['primary_clinician_membership_id' => $this->dr2->id]);
        $this->c['unassigned'] = $this->clientIn($org);
        $this->c['dr2s'] = $this->clientIn($org, ['primary_clinician_membership_id' => $this->dr2->id]);

        $this->bookAppointment($org, $this->c['seen_by_dr1'], $this->dr1, 'completed');
        $this->bookAppointment($org, $this->c['cancelled_with_dr1'], $this->dr1, 'cancelled');
    }

    /** @return list<string> client keys visible to $member, sorted */
    private function visibleTo(OrganizationMembership $member): array
    {
        $ids = $this->actAs($member, $this->created->organization, fn () => ClientVisibility::apply(Client::query(), $member)->pluck('id')->all());

        $keys = array_keys(array_filter($this->c, fn (Client $client) => in_array($client->id, $ids, true)));
        sort($keys);

        return $keys;
    }

    #[Test]
    public function a_clinician_with_view_sees_their_primary_clients_and_clients_they_have_had_an_appointment_with(): void
    {
        $this->assertSame(['cancelled_with_dr1', 'own', 'seen_by_dr1'], $this->visibleTo($this->dr1));
    }

    #[Test]
    public function an_appointment_of_any_status_counts_but_only_the_clinicians_own(): void
    {
        // dr2 is primary for three clients and never the clinician on an appointment.
        $this->assertSame(['cancelled_with_dr1', 'dr2s', 'seen_by_dr1'], $this->visibleTo($this->dr2));
    }

    #[Test]
    public function view_all_sees_every_client_and_a_member_with_neither_permission_sees_none(): void
    {
        $this->assertCount(5, $this->visibleTo($this->supervisor));
        $this->assertSame([], $this->visibleTo($this->nobody));
    }

    #[Test]
    public function the_organization_administrator_sees_everything(): void
    {
        $this->assertCount(5, $this->visibleTo($this->created->ownerMembership));
    }

    #[Test]
    public function the_single_record_check_agrees_with_the_query_scope_for_every_member_and_client(): void
    {
        foreach ([$this->dr1, $this->dr2, $this->supervisor, $this->nobody, $this->created->ownerMembership] as $member) {
            $visible = $this->visibleTo($member);

            foreach ($this->c as $key => $client) {
                $allowed = $this->actAs($member, $this->created->organization, fn () => ClientVisibility::allows($client, $member));

                $this->assertSame(in_array($key, $visible, true), $allowed, "{$key} for member {$member->id}");
            }
        }
    }

    #[Test]
    public function the_permission_helpers_describe_the_member(): void
    {
        $this->assertTrue(ClientVisibility::seesAll($this->supervisor));
        $this->assertFalse(ClientVisibility::seesAll($this->dr1));
        $this->assertTrue(ClientVisibility::hasAnyAccess($this->dr1));
        $this->assertFalse(ClientVisibility::hasAnyAccess($this->nobody));
    }

    #[Test]
    public function revoking_view_removes_the_primary_clinician_rule_too(): void
    {
        $this->revokeFromRole($this->created->organization, 'clinician', 'clients.view');

        $this->assertSame([], $this->visibleTo($this->dr1));
    }

    #[Test]
    public function granting_view_all_widens_the_scope_at_once(): void
    {
        $this->assertSame(['cancelled_with_dr1', 'own', 'seen_by_dr1'], $this->visibleTo($this->dr1));

        $role = \App\Models\Role::query()->forOrganization($this->created->organization->id)->where('key', 'clinician')->firstOrFail();
        \App\Models\RolePermission::query()->insert(['role_id' => $role->id, 'permission_key' => 'clients.view_all', 'scope' => 'organization']);
        app(\App\Domain\Identity\PermissionResolver::class)->flush();

        $this->assertCount(5, $this->visibleTo($this->dr1));
    }

    #[Test]
    public function another_organizations_clients_are_never_visible_even_to_view_all(): void
    {
        $other = $this->createOrganization();
        $foreign = $this->clientIn($other->organization);

        // The scope runs inside the searcher's own organization: the foreign client is simply not there.
        $ids = $this->actAs($this->supervisor, $this->created->organization, fn () => ClientVisibility::apply(Client::query(), $this->supervisor)->pluck('id')->all());
        $this->assertNotContains($foreign->id, $ids);

        // And the single-record check refuses a client of another organization outright.
        $this->assertFalse($this->actAs($this->supervisor, $this->created->organization, fn () => ClientVisibility::allows($foreign, $this->supervisor)));
    }

    #[Test]
    public function a_clinician_of_another_organization_does_not_gain_access_through_appointments(): void
    {
        // The same person is a clinician in two organizations; appointments in B never open A's records.
        $other = $this->createOrganization();
        $user = $this->dr1->user;
        $membershipInB = $this->addStaff($other->organization, 'clinician', user: $user);
        $clientInB = $this->clientIn($other->organization, ['primary_clinician_membership_id' => $membershipInB->id]);

        $inB = $this->actAs($membershipInB, $other->organization, fn () => ClientVisibility::apply(Client::query(), $membershipInB)->pluck('id')->all());
        $this->assertSame([$clientInB->id], $inB);

        $this->assertSame(['cancelled_with_dr1', 'own', 'seen_by_dr1'], $this->visibleTo($this->dr1));
    }

    #[Test]
    public function a_member_who_is_no_longer_active_sees_nothing_whatever_their_roles_still_say(): void
    {
        foreach ([$this->dr1, $this->supervisor, $this->created->ownerMembership] as $member) {
            foreach (['suspended', 'deactivated'] as $status) {
                $this->inTenant($this->created->organization, fn () => OrganizationMembership::query()->whereKey($member->id)->first()->forceFill(['status' => $status])->save());
                $inactive = $this->membershipRecord($member->id);

                $ids = $this->actAs($inactive, $this->created->organization, fn () => ClientVisibility::apply(Client::query(), $inactive)->pluck('id')->all());
                $this->assertSame([], $ids, "{$status} member");

                foreach ($this->c as $client) {
                    $this->assertFalse(ClientVisibility::allows($client, $inactive), "{$status} member");
                }
                $this->assertFalse(ClientVisibility::hasAnyAccess($inactive));
                $this->assertFalse(ClientVisibility::seesAll($inactive));
            }

            $this->inTenant($this->created->organization, fn () => OrganizationMembership::query()->whereKey($member->id)->first()->forceFill(['status' => 'active'])->save());
        }
    }

    #[Test]
    public function the_scope_is_one_cheap_query_whatever_the_number_of_clients(): void
    {
        [$ids, $statements] = $this->actAs($this->dr1, $this->created->organization, fn () => $this->recordingQueries(
            fn () => ClientVisibility::apply(Client::query(), $this->dr1)->pluck('id')->all(),
        ));

        $this->assertCount(3, $ids);
        // permissions of the membership (1) + the clients (1)
        $this->assertLessThanOrEqual(2, count($statements), implode("\n", $statements));
    }
}
