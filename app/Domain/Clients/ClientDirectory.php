<?php

namespace App\Domain\Clients;

use App\Models\Client;
use App\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
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
        'primary_clinician_membership_id', 'created_at', 'client_type', 'billing_type', 'is_virtual',
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

        if ($filters->location === ClientListFilters::VIRTUAL) {
            $query->where('is_virtual', true);
        } elseif ($filters->location !== null) {
            $query->where('primary_location_id', $filters->location);
        }

        if ($filters->type !== null) {
            $query->where('client_type', $filters->type);
        }

        if ($filters->billing !== null) {
            $query->where('billing_type', $filters->billing);
        }

        if ($filters->mine) {
            $query->where('primary_clinician_membership_id', $membership->id);
        }

        if ($filters->visitedFrom !== null || $filters->visitedTo !== null) {
            $timezone = tenant()->organizationOrFail()->timezone;
            $from = $filters->visitedFrom !== null ? CarbonImmutable::parse($filters->visitedFrom, $timezone)->startOfDay()->utc() : null;
            $to = $filters->visitedTo !== null ? CarbonImmutable::parse($filters->visitedTo, $timezone)->addDay()->startOfDay()->utc() : null;

            // "Last appointment in this range": a completed visit inside it (the same rule the list's Last Visit column uses).
            $query->whereExists(function ($visit) use ($membership, $from, $to) {
                $visit->selectRaw('1')->from('appointments')
                    ->whereColumn('appointments.client_id', 'clients.id')
                    ->where('appointments.organization_id', $membership->organization_id)
                    ->where('appointments.status', 'completed')
                    ->when($from, fn ($q) => $q->where('appointments.starts_at', '>=', $from->format('Y-m-d H:i:s.uP')))
                    ->when($to, fn ($q) => $q->where('appointments.starts_at', '<', $to->format('Y-m-d H:i:s.uP')));
            });
        }

        $direction = $filters->direction;

        match ($filters->sort) {
            'client_number' => $query->orderBy('client_number', $direction),
            'created' => $query->orderBy('created_at', $direction)->orderBy('id', $direction),
            default => $query->orderBy('last_name', $direction)->orderBy('first_name', $direction)->orderBy('id', $direction),
        };

        return $query->with('primaryClinician.user:id,name');
    }

    /**
     * The list screen's query: query() plus the primary e-mail the Client column shows.
     * (query() stays e-mail-free for every other consumer.)
     *
     * @return Builder<Client>
     */
    public function listQuery(OrganizationMembership $membership, ClientListFilters $filters): Builder
    {
        return $this->query($membership, $filters)->addSelect('email');
    }
}
