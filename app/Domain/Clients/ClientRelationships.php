<?php

namespace App\Domain\Clients;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The "Relationship" column of the client list (and the links on a profile): who each client is connected to.
 * For a PAGE of clients in a fixed number of queries whatever the page size (no N+1):
 *
 *   1. contacts that matter here — parents/guardians, partners/spouses, emergency contacts (one query);
 *   2. the couples each individual on the page belongs to (one query);
 *   3. the members of each couple on the page (one query);
 *
 * plus the primary clinician, which the list has already eager-loaded. Linked CLIENTS (couples, members) are
 * read through ClientVisibility: a link to someone the viewer may not see is left out entirely, so the column
 * never reveals a record the list would not.
 *
 * Lines, in this order: Clinician, Members (a couple) / Couple (a member), Guardian, Partner, Emergency.
 * A contact who is both a guardian and an emergency contact appears once, as a guardian.
 */
final class ClientRelationships
{
    /** Lines shown per row; the rest are counted ("+N more"). */
    public const MAX_LINES = 3;

    /** Linked rows read per page, at most (bounded whatever the data). */
    private const MAX_ROWS = 400;

    /**
     * @param  Collection<int, Client>  $clients  the page (primaryClinician.user loaded or loadable; client_type selected)
     * @return array<string, array{lines: list<array{label: string, items: list<array{text: string, client: ?Client}>}>, more: int}>
     */
    public function forPage(Collection $clients, OrganizationMembership $viewer): array
    {
        $all = $this->all($clients, $viewer);
        $out = [];
        foreach ($all as $id => $lines) {
            $out[$id] = ['lines' => array_slice($lines, 0, self::MAX_LINES), 'more' => max(0, count($lines) - self::MAX_LINES)];
        }

        return $out;
    }

    /**
     * Every line, uncapped (a profile shows them all).
     *
     * @param  Collection<int, Client>  $clients
     * @return array<string, list<array{label: string, items: list<array{text: string, client: ?Client}>}>>
     */
    public function all(Collection $clients, OrganizationMembership $viewer): array
    {
        $lines = [];
        foreach ($clients as $client) {
            $lines[$client->id] = [];
        }
        if ($clients->isEmpty()) {
            return $lines;
        }

        $couples = $clients->filter(fn (Client $c) => $c->isCouple())->pluck('id')->all();
        $people = $clients->reject(fn (Client $c) => $c->isCouple())->pluck('id')->all();

        $membersOf = $this->linked($couples, 'couple_client_id', 'member_client_id', $viewer);
        $couplesOf = $this->linked($people, 'member_client_id', 'couple_client_id', $viewer);
        $contacts = $this->contacts($clients->pluck('id')->all());

        foreach ($clients as $client) {
            $id = $client->id;

            $clinician = $client->relationLoaded('primaryClinician') ? $client->getRelation('primaryClinician') : $client->primaryClinician()->with('user:id,name')->first();
            if ($clinician !== null) {
                $lines[$id][] = ['label' => 'Clinician', 'items' => [['text' => $clinician->professionalName(), 'client' => null]]];
            }

            if (($membersOf[$id] ?? []) !== []) {
                $lines[$id][] = ['label' => 'Members', 'items' => array_map(fn (Client $m) => ['text' => $m->displayName(), 'client' => $m], $membersOf[$id])];
            }
            foreach ($couplesOf[$id] ?? [] as $couple) {
                $lines[$id][] = ['label' => 'Couple', 'items' => [['text' => $couple->displayName(), 'client' => $couple]]];
            }

            $byKind = ['Guardian' => [], 'Partner' => [], 'Emergency' => []];
            foreach ($contacts[$id] ?? [] as $contact) {
                $kind = match (true) {
                    $contact->relationship_type?->isGuardian() ?? false => 'Guardian',
                    $contact->relationship_type?->isPartner() ?? false => 'Partner',
                    default => 'Emergency',
                };
                $byKind[$kind][] = ['text' => $contact->name, 'client' => null];
            }
            foreach ($byKind as $label => $items) {
                if ($items !== []) {
                    $lines[$id][] = ['label' => $label, 'items' => $items];
                }
            }
        }

        return $lines;
    }

    /**
     * The clients on the other side of a couple link, visible to $viewer, per page client.
     *
     * @param  list<string>  $ids
     * @return array<string, list<Client>>
     */
    private function linked(array $ids, string $from, string $to, OrganizationMembership $viewer): array
    {
        if ($ids === []) {
            return [];
        }

        $query = Client::query()
            ->select(['clients.id', 'clients.organization_id', 'clients.first_name', 'clients.last_name', 'clients.preferred_name', 'clients.client_type', "client_couple_members.{$from} as linked_from"])
            ->join('client_couple_members', function ($join) use ($to) {
                $join->on('client_couple_members.organization_id', '=', 'clients.organization_id')
                    ->on("client_couple_members.{$to}", '=', 'clients.id');
            })
            ->where('client_couple_members.organization_id', $viewer->organization_id)
            ->whereIn("client_couple_members.{$from}", $ids)
            ->orderBy('clients.first_name')->orderBy('clients.last_name')->orderBy('clients.id')
            ->limit(self::MAX_ROWS);
        ClientVisibility::apply($query, $viewer);

        $out = [];
        foreach ($query->get() as $client) {
            $out[$client->getAttributes()['linked_from']][] = $client;
        }

        return $out;
    }

    /**
     * Guardians, partners and emergency contacts of the page's clients, in the contact list's order.
     *
     * @param  list<string>  $ids
     * @return array<string, list<ClientContact>>
     */
    private function contacts(array $ids): array
    {
        $rows = ClientContact::query()
            ->select(['id', 'organization_id', 'client_id', 'name', 'relationship_type', 'is_emergency_contact'])
            ->whereIn('client_id', $ids)
            ->where(function (Builder $q) {
                $q->whereIn('relationship_type', [...RelationshipType::guardianValues(), ...RelationshipType::partnerValues()])
                    ->orWhere('is_emergency_contact', true);
            })
            ->orderBy('client_id')->orderBy('sort')->orderBy('name')
            ->limit(self::MAX_ROWS)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->client_id][] = $row;
        }

        return $out;
    }
}
