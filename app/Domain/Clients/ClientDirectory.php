<?php

namespace App\Domain\Clients;

use App\Models\Client;
use App\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\Builder;

/**
 * The client list as a query: what this member may see (ClientVisibility),
 * narrowed by the search and the filters, sorted. Selects only the columns
 * the list shows (no notes, no e-mail, no address): data minimisation and a
 * lean payload.
 */
final class ClientDirectory
{
    /** Columns the list needs. Reading any other attribute of a row throws under strict mode: add it here first. */
    public const COLUMNS = [
        'id', 'organization_id', 'record_environment', 'client_number', 'status',
        'first_name', 'last_name', 'preferred_name', 'date_of_birth', 'phone',
        'primary_clinician_membership_id', 'created_at',
    ];

    /** @return Builder<Client> */
    public function query(OrganizationMembership $membership, ClientListFilters $filters): Builder
    {
        $query = Client::query()->select(self::COLUMNS)->whereIn('status', $filters->statuses());

        ClientVisibility::apply($query, $membership);
        ClientSearch::apply($query, $filters->q);

        match ($filters->records) {
            'live' => $query->live(),
            'demo' => $query->demo(),
            default => null,
        };

        if ($filters->clinician === ClientListFilters::UNASSIGNED) {
            $query->whereNull('primary_clinician_membership_id');
        } elseif ($filters->clinician !== null) {
            $query->where('primary_clinician_membership_id', $filters->clinician);
        }

        $direction = $filters->direction;

        match ($filters->sort) {
            'client_number' => $query->orderBy('client_number', $direction),
            'created' => $query->orderBy('created_at', $direction)->orderBy('id', $direction),
            default => $query->orderBy('last_name', $direction)->orderBy('first_name', $direction)->orderBy('id', $direction),
        };

        return $query->with('primaryClinician.user:id,name');
    }
}
