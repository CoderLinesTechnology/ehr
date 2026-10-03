<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\ClientRelationships;
use App\Domain\Clients\CoupleMembers;
use App\Domain\Clients\SaveClientContact;
use App\Domain\Platform\CreatedOrganization;
use App\Models\Client;
use App\Models\OrganizationMembership;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;

/**
 * The list's Relationship column: per page, in a fixed number of queries, and never naming a linked client
 * the viewer may not see.
 */
class ClientRelationshipsTest extends ClientsTestCase
{
    private CreatedOrganization $created;

    private OrganizationMembership $dr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->createOrganization();
        $this->dr = $this->addStaff($this->created->organization, 'clinician', ['name_prefix' => 'Dr.']);
    }

    /** @param Collection<int, Client> $clients @return array<string, list<string>> client id => "Label: a, b" lines */
    private function lines(Collection $clients, OrganizationMembership $viewer, bool $capped = false): array
    {
        return $this->inTenant($this->created->organization, function () use ($clients, $viewer, $capped) {
            $page = Client::query()->whereKey($clients->pluck('id'))->with('primaryClinician.user:id,name')->get();
            $reader = app(ClientRelationships::class);
            $out = [];
            foreach ($capped ? $reader->forPage($page, $viewer) : array_map(fn ($l) => ['lines' => $l, 'more' => 0], $reader->all($page, $viewer)) as $id => $row) {
                $out[$id] = array_map(fn ($line) => $line['label'].': '.implode(', ', array_column($line['items'], 'text')), $row['lines']);
                if ($row['more'] > 0) {
                    $out[$id][] = '+'.$row['more'].' more';
                }
            }

            return $out;
        }, $viewer);
    }

    private function link(Client $couple, Client ...$members): void
    {
        $this->inTenant($this->created->organization, function () use ($couple, $members) {
            $couple->forceFill(['client_type' => 'couple'])->save();
            foreach ($members as $member) {
                app(CoupleMembers::class)->link($couple, $member);
            }
        });
    }

    private function contact(Client $client, array $input): void
    {
        $this->inTenant($this->created->organization, fn () => app(SaveClientContact::class)($client, $input));
    }

    #[Test]
    public function each_client_lists_its_clinician_couple_members_guardians_partners_and_emergency_contacts(): void
    {
        $org = $this->created->organization;
        $emily = $this->clientIn($org, ['first_name' => 'Emily', 'last_name' => 'Johnson', 'primary_clinician_membership_id' => $this->dr->id]);
        $michael = $this->clientIn($org, ['first_name' => 'Michael', 'last_name' => 'Johnson']);
        $couple = $this->clientIn($org, ['first_name' => 'Emily & Michael', 'last_name' => 'Johnson']);
        $this->link($couple, $emily, $michael);
        $this->contact($emily, ['name' => 'Mary Brown', 'relationship_type' => 'guardian', 'phone' => '0244100001', 'is_emergency_contact' => true]);
        $this->contact($emily, ['name' => 'John Johnson', 'relationship_type' => 'sibling', 'is_emergency_contact' => true]);
        $this->contact($michael, ['name' => 'Kate Lee', 'relationship_type' => 'partner']);
        $this->contact($michael, ['name' => 'Not Shown', 'relationship_type' => 'friend']);

        $lines = $this->lines(collect([$emily, $michael, $couple]), $this->created->ownerMembership);

        $clinician = 'Clinician: Dr. '.$this->dr->user->name;
        $this->assertSame([$clinician, 'Couple: Emily & Michael Johnson', 'Guardian: Mary Brown', 'Emergency: John Johnson'], $lines[$emily->id]);
        $this->assertSame(['Couple: Emily & Michael Johnson', 'Partner: Kate Lee'], $lines[$michael->id]);
        $this->assertSame(['Members: Emily Johnson, Michael Johnson'], $lines[$couple->id]);

        // The list shows three lines and counts the rest.
        $capped = $this->lines(collect([$emily]), $this->created->ownerMembership, capped: true);
        $this->assertSame([$clinician, 'Couple: Emily & Michael Johnson', 'Guardian: Mary Brown', '+1 more'], $capped[$emily->id]);
    }

    #[Test]
    public function a_linked_client_the_viewer_may_not_see_is_left_out(): void
    {
        $org = $this->created->organization;
        $mine = $this->clientIn($org, ['first_name' => 'Emily', 'last_name' => 'Johnson', 'primary_clinician_membership_id' => $this->dr->id]);
        $hidden = $this->clientIn($org, ['first_name' => 'Secret', 'last_name' => 'Partner']);
        $couple = $this->clientIn($org, ['first_name' => 'Emily & Secret', 'last_name' => 'Mixed']);
        $this->link($couple, $mine, $hidden);

        $lines = $this->lines(collect([$mine]), $this->dr);
        $this->assertSame(['Clinician: Dr. '.$this->dr->user->name], $lines[$mine->id], 'the couple record is not theirs to see');

        // Made visible (their client), the couple shows — but its hidden member still does not.
        $this->inTenant($org, fn () => $couple->forceFill(['primary_clinician_membership_id' => $this->dr->id])->save());
        $this->assertSame(['Clinician: Dr. '.$this->dr->user->name, 'Couple: Emily & Secret Mixed'], $this->lines(collect([$mine]), $this->dr)[$mine->id]);
        $this->assertSame(['Clinician: Dr. '.$this->dr->user->name, 'Members: Emily Johnson'], $this->lines(collect([$couple]), $this->dr)[$couple->id]);
    }

    #[Test]
    public function a_page_costs_the_same_number_of_queries_for_two_rows_or_eight(): void
    {
        $org = $this->created->organization;
        $make = function (int $n) use ($org): Collection {
            $rows = collect();
            for ($i = 0; $i < $n; $i += 2) {
                $a = $this->clientIn($org, ['primary_clinician_membership_id' => $this->dr->id]);
                $couple = $this->clientIn($org);
                $this->link($couple, $a, $this->clientIn($org));
                $this->contact($a, ['name' => 'Guardian '.$i, 'relationship_type' => 'parent', 'phone' => '0244100001']);
                $rows->push($a, $couple);
            }

            return $rows;
        };
        $two = $make(2);
        $eight = $make(8);

        $count = function (Collection $clients): int {
            return $this->inTenant($this->created->organization, function () use ($clients) {
                $page = Client::query()->whereKey($clients->pluck('id'))->with('primaryClinician.user:id,name')->get();

                return count($this->recordingQueries(fn () => app(ClientRelationships::class)->forPage($page, $this->created->ownerMembership))[1]);
            }, $this->created->ownerMembership);
        };

        $count($two);   // warm the permission cache
        $this->assertSame($count($two), $count($eight));
        $this->assertLessThanOrEqual(4, $count($eight));
    }
}
