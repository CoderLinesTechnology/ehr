<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\ClientContactPoints;
use App\Domain\Clients\ClientStatus;
use App\Domain\Clients\CoupleMembers;
use App\Domain\Clients\CreateCouple;
use App\Domain\Clients\UpdateClient;
use App\Domain\Platform\CreatedOrganization;
use App\Domain\Shared\DomainException;
use App\Models\Client;
use App\Models\ClientCoupleMember;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * Couples: a client record of type couple linked to exactly two individual clients, existing or created in
 * the same transaction; a member may be in several couples but only once in each.
 */
class CoupleTest extends ClientsTestCase
{
    private CreatedOrganization $created;

    private OrganizationMembership $clinician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();
        $this->clinician = $this->addStaff($this->created->organization, 'clinician');
    }

    /** @param array<string, mixed> $input */
    private function couple(array $input, ?OrganizationMembership $as = null): Client
    {
        $as ??= $this->created->ownerMembership;

        return $this->actAs($as, $this->created->organization, fn () => app(CreateCouple::class)($input, User::query()->findOrFail($as->user_id)));
    }

    private function person(string $first, string $last, array $extra = []): Client
    {
        return $this->clientIn($this->created->organization, ['first_name' => $first, 'last_name' => $last] + $extra);
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

    /** @return list<string> */
    private function memberIds(Client $couple): array
    {
        return DB::table('client_couple_members')->where('couple_client_id', $couple->id)->orderBy('member_client_id')->pluck('member_client_id')->all();
    }

    #[Test]
    public function two_existing_clients_become_a_couple_with_its_own_number_and_a_derived_name(): void
    {
        $emily = $this->person('Emily', 'Johnson');
        $michael = $this->person('Michael', 'Lee');

        $couple = $this->couple(['partners' => ['1' => ['mode' => 'existing', 'client_id' => $emily->id], '2' => ['mode' => 'existing', 'client_id' => $michael->id]], 'billing_type' => 'insurance']);

        $this->assertTrue($couple->isCouple());
        $this->assertSame(['Emily Johnson & Michael', 'Lee'], [$couple->first_name, $couple->last_name]);
        $this->assertSame(3, $couple->client_number);
        $this->assertSame('insurance', $couple->billing_type->value);
        $this->assertEqualsCanonicalizing([$emily->id, $michael->id], $this->memberIds($couple));

        $added = $this->auditEntries('client.couple_member_added');
        $this->assertCount(2, $added);
        $this->assertSame($couple->id, $added[0]->subject_id);
        $this->assertStringNotContainsString('Emily', json_encode($added->all()), 'the trail names members by number only');
        $this->assertSame('couple', $this->auditEntries('client.created')->last()->metadata['client_type']);
    }

    #[Test]
    public function the_name_is_shared_when_the_last_names_are(): void
    {
        $this->assertSame(['first_name' => 'Emily & Michael', 'last_name' => 'Johnson'], CoupleMembers::name('Emily', 'Johnson', 'Michael', 'johnson'));
        $this->assertSame(['first_name' => 'Emily Johnson & Michael', 'last_name' => 'Lee'], CoupleMembers::name('Emily', 'Johnson', 'Michael', 'Lee'));
    }

    #[Test]
    public function a_new_partner_is_created_in_the_same_transaction_and_inherits_the_couples_care_settings(): void
    {
        $emily = $this->person('Emily', 'Johnson');

        $couple = $this->couple([
            'partners' => [
                '1' => ['mode' => 'existing', 'client_id' => $emily->id],
                '2' => ['mode' => 'new', 'first_name' => 'Michael', 'last_name' => 'Johnson', 'phone' => '024 410 0007', 'email' => 'Michael@Example.org'],
            ],
            'primary_clinician_membership_id' => $this->clinician->id,
            'primary_location_id' => 'virtual',
            'billing_type' => 'insurance',
        ]);

        $this->assertSame(['Emily & Michael', 'Johnson'], [$couple->first_name, $couple->last_name]);
        $this->assertTrue($couple->is_virtual);

        $michael = $this->inTenant($this->created->organization, fn () => Client::query()->where('first_name', 'Michael')->firstOrFail());
        $this->assertSame('adult', $michael->client_type->value);
        $this->assertSame([$this->clinician->id, true, 'insurance'], [$michael->primary_clinician_membership_id, $michael->is_virtual, $michael->billing_type->value]);
        $this->assertSame(['+233244100007', 'michael@example.org'], [$michael->phone, $michael->email]);
        $this->assertEqualsCanonicalizing([$emily->id, $michael->id], $this->memberIds($couple));
        $this->assertLessThan($couple->client_number, $michael->client_number, 'every client has its own number; the new member is registered first');
    }

    #[Test]
    public function members_must_be_two_different_visible_individual_clients_and_a_refusal_writes_nothing(): void
    {
        $emily = $this->person('Emily', 'Johnson');
        $michael = $this->person('Michael', 'Lee');
        $archived = $this->clientIn($this->created->organization, ['first_name' => 'Old', 'last_name' => 'Record', 'status' => ClientStatus::Archived]);
        $existing = fn (Client $a, Client $b) => ['partners' => ['1' => ['mode' => 'existing', 'client_id' => $a->id], '2' => ['mode' => 'existing', 'client_id' => $b->id]]];
        $couple = $this->couple($existing($emily, $michael));

        $this->refused('member_is_couple', fn () => $this->couple($existing($emily, $couple)));
        $this->refused('same_member_twice', fn () => $this->couple($existing($emily, $emily)));
        $this->refused('member_archived', fn () => $this->couple($existing($emily, $archived)));
        $this->refused('couple_needs_two', fn () => $this->couple(['partners' => ['1' => ['mode' => 'existing', 'client_id' => $emily->id]]]));
        $this->refused('member_not_found', fn () => $this->couple(['partners' => ['1' => ['mode' => 'existing', 'client_id' => 'nope'], '2' => ['mode' => 'existing', 'client_id' => $michael->id]]]));
        $e = $this->refused('first_name_required', fn () => $this->couple(['partners' => ['1' => ['mode' => 'existing', 'client_id' => $emily->id], '2' => ['mode' => 'new', 'last_name' => 'Lee', 'phone' => '0244100009']]]));
        $this->assertSame('partners.2.first_name', $e->field());
        $e = $this->refused('invalid_phone', fn () => $this->couple(['partners' => ['1' => ['mode' => 'existing', 'client_id' => $emily->id], '2' => ['mode' => 'new', 'first_name' => 'A', 'last_name' => 'B', 'phone' => '12']]]));
        $this->assertSame('partners.2.phone', $e->field());

        // A clinician may only link the clients they see (their own).
        $this->refused('member_not_found', fn () => $this->couple($existing($emily, $michael), $this->clinician));

        $this->assertSame(1, DB::table('clients')->where('client_type', 'couple')->count());
        $this->assertSame(2, DB::table('client_couple_members')->count());
        $this->assertSame(4, DB::table('clients')->count(), 'no partial couple, no stray new member');
    }

    #[Test]
    public function a_client_can_be_in_several_couples_but_once_in_each(): void
    {
        $a = $this->person('Ama', 'Owusu');
        $b = $this->person('Kofi', 'Owusu');
        $c = $this->person('Esi', 'Mensah');
        $existing = fn (Client $x, Client $y) => ['partners' => ['1' => ['mode' => 'existing', 'client_id' => $x->id], '2' => ['mode' => 'existing', 'client_id' => $y->id]]];

        $first = $this->couple($existing($a, $b));
        $this->couple($existing($a, $c));
        $this->assertSame(2, DB::table('client_couple_members')->where('member_client_id', $a->id)->count());

        $this->expectException(QueryException::class);
        $this->inTenant($this->created->organization, function () use ($first, $a) {
            (new ClientCoupleMember)->forceFill(['record_environment' => 'live', 'couple_client_id' => $first->id, 'member_client_id' => $a->id])->save();
        });
    }

    #[Test]
    public function the_database_keeps_a_link_inside_one_organization_and_one_environment_and_never_to_itself(): void
    {
        $couple = $this->couple(['partners' => [
            '1' => ['mode' => 'existing', 'client_id' => $this->person('A', 'One')->id],
            '2' => ['mode' => 'existing', 'client_id' => $this->person('B', 'Two')->id],
        ]]);
        $demo = $this->demoClientIn($this->created->organization);
        $other = $this->clientIn($this->createOrganization()->organization);

        foreach ([$demo->id, $other->id, $couple->id] as $member) {
            try {
                DB::transaction(fn () => DB::table('client_couple_members')->insert([
                    'id' => (string) Str::uuid7(), 'organization_id' => $this->created->organization->id, 'record_environment' => 'live',
                    'couple_client_id' => $couple->id, 'member_client_id' => $member, 'created_at' => now(), 'updated_at' => now(),
                ]));
                $this->fail('The database accepted a link it must refuse.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function a_couple_stays_a_couple_and_its_members_can_be_replaced_with_an_audit(): void
    {
        $a = $this->person('Ama', 'Owusu');
        $b = $this->person('Kofi', 'Owusu');
        $c = $this->person('Esi', 'Mensah');
        $couple = $this->couple(['partners' => ['1' => ['mode' => 'existing', 'client_id' => $a->id], '2' => ['mode' => 'existing', 'client_id' => $b->id]]]);
        $update = fn (array $input) => $this->actAs($this->created->ownerMembership, $this->created->organization, fn () => app(UpdateClient::class)($couple, $input));

        $this->refused('couple_type_fixed', fn () => $update(['client_type' => 'adult']));
        $this->refused('couple_needs_two', fn () => $update(['members' => [$a->id]]));
        $this->refused('same_member_twice', fn () => $update(['members' => [$a->id, $a->id]]));

        $update(['members' => [$a->id, $c->id], 'first_name' => 'Ama & Esi', 'last_name' => 'Owusu-Mensah']);

        $this->assertEqualsCanonicalizing([$a->id, $c->id], $this->memberIds($couple));
        $this->assertSame('Ama & Esi', $couple->first_name);
        $this->assertSame($b->id, $this->auditEntries('client.couple_member_removed')->first()->metadata['member_client_id']);
        $this->assertSame($c->id, $this->auditEntries('client.couple_member_added')->last()->metadata['member_client_id']);
    }

    #[Test]
    public function deleting_either_side_removes_the_link_with_it(): void
    {
        $a = $this->demoClientIn($this->created->organization, ['first_name' => 'Demo', 'last_name' => 'A']);
        $b = $this->demoClientIn($this->created->organization, ['first_name' => 'Demo', 'last_name' => 'B']);
        $couple = $this->demoClientIn($this->created->organization, ['first_name' => 'Demo', 'last_name' => 'Couple']);
        $this->inTenant($this->created->organization, function () use ($couple, $a, $b) {
            $couple->forceFill(['client_type' => 'couple'])->save();
            app(CoupleMembers::class)->link($couple, $a);
            app(CoupleMembers::class)->link($couple, $b);
            app(ClientContactPoints::class)->sync($a, ['email' => [['value' => 'demo@example.org', 'label' => 'home', 'is_primary' => true]]], []);
        });
        $this->assertSame(1, DB::table('client_contact_points')->where('client_id', $a->id)->count());

        // The demo-data purge deletes demo clients; their points, contacts and links go with them (cascade).
        DB::transaction(function () use ($a, $couple) {
            DB::statement("SET LOCAL app.purging_demo = 'on'");
            DB::table('clients')->where('id', $a->id)->delete();
            $this->assertSame(1, DB::table('client_couple_members')->where('couple_client_id', $couple->id)->count());
            DB::table('clients')->where('id', $couple->id)->delete();
        });

        $this->assertSame(0, DB::table('client_couple_members')->count());
        $this->assertSame(0, DB::table('client_contact_points')->whereIn('client_id', [$a->id, $couple->id])->count());
    }
}
