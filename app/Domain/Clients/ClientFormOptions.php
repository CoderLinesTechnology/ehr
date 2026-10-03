<?php

namespace App\Domain\Clients;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Support\Regions;

/**
 * The choices behind the client form's and filters' selects. Bounded reads
 * (an organization has a few dozen staff and a handful of locations).
 */
final class ClientFormOptions
{
    private const LIMIT = 200;

    /** Clients offered in a partner / member select before anything is typed (the type-ahead finds the rest). */
    public const PARTNER_OPTIONS = 100;

    public function __construct(
        private readonly ClientRequirements $requirements,
        private readonly ClientContactPoints $contactPoints,
    ) {}

    /**
     * Everything the client form partial (resources/views/app/clients/_form.blade.php) needs, for a new client
     * ($client null: clients/create and New appointment) or the one being edited.
     *
     * @return array<string, mixed>
     */
    public function form(?Client $client, OrganizationMembership $viewer): array
    {
        $organization = tenant()->organizationOrFail();
        $new = $client === null;

        $members = [];
        if ($client?->isCouple()) {
            $members = Client::query()
                ->select(['clients.id', 'clients.organization_id', 'clients.first_name', 'clients.last_name', 'clients.preferred_name', 'clients.client_number', 'clients.client_type'])
                ->join('client_couple_members', fn ($j) => $j->on('client_couple_members.member_client_id', '=', 'clients.id')->on('client_couple_members.organization_id', '=', 'clients.organization_id'))
                ->where('client_couple_members.couple_client_id', $client->id)
                ->orderBy('client_couple_members.created_at')->orderBy('clients.id')
                ->limit(CoupleMembers::SIZE)
                ->get()->all();
        }

        return [
            'client' => $client,
            'clientType' => $client?->client_type ?? ClientType::Adult,
            'clinicians' => $this->clinicians($client?->primary_clinician_membership_id),
            'locations' => [ClientAttributes::VIRTUAL => 'Virtual (telehealth)'] + $this->locations($client?->primary_location_id),
            'sexOptions' => ClientSex::options(),
            'contactMethods' => ContactMethod::options(),
            'countries' => Regions::countries(),
            'country' => $organization->country_code,
            'billingOptions' => BillingType::options(),
            'emailLabels' => ContactPointLabel::optionsFor(ContactPointKind::Email),
            'phoneLabels' => ContactPointLabel::optionsFor(ContactPointKind::Phone),
            'guardianTypes' => RelationshipType::guardianOptions(),
            'points' => $client !== null ? $this->contactPoints->effective($client) : ['email' => [], 'phone' => []],
            'guardians' => $client !== null
                ? ClientContact::query()->where('client_id', $client->id)->whereIn('relationship_type', RelationshipType::guardianValues())->orderBy('sort')->orderBy('name')->limit(25)->get()
                : collect(),
            'members' => $members,
            'partnerOptions' => ($new || $client->isCouple()) ? $this->partnerOptions($viewer, array_map(fn (Client $m) => $m->id, $members)) : [],
            'dobRequired' => $new && $this->requirements->dateOfBirthRequired($organization),
            'contactRequired' => $new && $this->requirements->contactRequired($organization),
        ];
    }

    /**
     * Individual clients the viewer may see, for the couple form's partner selects (bounded; typing searches the
     * rest through the global search's JSON answer).
     *
     * @param  list<string>  $alsoInclude  the couple's current members
     * @return array<string, string> client id => "Name · CL-0012"
     */
    public function partnerOptions(OrganizationMembership $viewer, array $alsoInclude = []): array
    {
        $query = Client::query()
            ->select(['id', 'organization_id', 'first_name', 'last_name', 'preferred_name', 'client_number'])
            ->where('client_type', '!=', ClientType::Couple->value)
            ->where('status', '!=', ClientStatus::Archived->value)
            ->where('record_environment', 'live')
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->limit(self::PARTNER_OPTIONS);
        ClientVisibility::apply($query, $viewer);
        $clients = $query->get();

        $missing = array_diff($alsoInclude, $clients->pluck('id')->all());
        if ($missing !== []) {
            $clients = $clients->concat(Client::query()->select(['id', 'organization_id', 'first_name', 'last_name', 'preferred_name', 'client_number'])->whereKey($missing)->get());
        }

        return $clients->mapWithKeys(fn (Client $c) => [$c->id => $c->displayName().' · '.$c->formattedNumber()])->all();
    }

    /**
     * Clinicians a client can be assigned to: active providers, plus
     * $alsoInclude (the clinician already assigned) even if they have since
     * left, so editing a client never silently drops them.
     *
     * @return array<string, string> membership id => "Name, Title"
     */
    public function clinicians(?string $alsoInclude = null): array
    {
        $memberships = OrganizationMembership::query()
            ->where(function ($query) use ($alsoInclude) {
                $query->where(fn ($provider) => $provider->where('status', 'active')->where('is_provider', true));
                if ($alsoInclude !== null) {
                    $query->orWhere('id', $alsoInclude);
                }
            })
            ->with('user:id,name')
            ->limit(self::LIMIT)
            ->get();

        $options = [];
        foreach ($memberships->sortBy(fn (OrganizationMembership $m) => mb_strtolower($m->displayName())) as $membership) {
            $label = $membership->displayName().($membership->title ? ', '.$membership->title : '');
            $options[$membership->id] = $membership->isActive() && $membership->is_provider ? $label : $label.' (no longer active)';
        }

        return $options;
    }

    /**
     * @return array<string, string> location id => name
     */
    public function locations(?string $alsoInclude = null): array
    {
        return Location::query()
            ->select(['id', 'name', 'is_active', 'sort'])
            ->where(function ($query) use ($alsoInclude) {
                $query->where('is_active', true);
                if ($alsoInclude !== null) {
                    $query->orWhere('id', $alsoInclude);
                }
            })
            ->orderBy('sort')->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->mapWithKeys(fn (Location $l) => [$l->id => $l->is_active ? $l->name : $l->name.' (closed)'])
            ->all();
    }
}
