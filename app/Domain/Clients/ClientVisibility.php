<?php

namespace App\Domain\Clients;

use App\Domain\Identity\PermissionResolver;
use App\Models\Client;
use App\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see which client. One rule, used by the list, the search, the
 * profile and every client sub-route (through ClientPolicy):
 *
 *   clients.view_all  → every client of the organization
 *   clients.view      → clients the member is the primary clinician of, or
 *                       whose appointment they have been the clinician on
 *                       (any status, past or future: continuity of care)
 *   neither           → none
 *
 * Only an ACTIVE membership sees anything: a suspended or deactivated member
 * keeps their roles on the row, and a check that reads the role alone would
 * keep working for them.
 *
 * Applied to a query so a hidden client never reaches the application; the
 * policy asks the same question about one record, so list and detail agree.
 */
final class ClientVisibility
{
    public const VIEW_ALL = 'clients.view_all';

    public const VIEW = 'clients.view';

    /**
     * Restrict a Client query to the records $membership may see.
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public static function apply(Builder $query, OrganizationMembership $membership): Builder
    {
        $permissions = app(PermissionResolver::class);

        if (! $membership->isActive()) {
            return $query->whereRaw('1 = 0');
        }

        if ($permissions->membershipHas($membership, self::VIEW_ALL)) {
            return $query;
        }

        if (! $permissions->membershipHas($membership, self::VIEW)) {
            return $query->whereRaw('1 = 0');
        }

        $clients = $query->getModel()->getTable();

        return $query->where(function (Builder $visible) use ($membership, $clients) {
            $visible->where("{$clients}.primary_clinician_membership_id", $membership->id)
                ->orWhereExists(function ($appointment) use ($membership, $clients) {
                    $appointment->selectRaw('1')
                        ->from('appointments')
                        ->whereColumn('appointments.client_id', "{$clients}.id")
                        ->whereColumn('appointments.organization_id', "{$clients}.organization_id")
                        // Also a constant: a list query makes PostgreSQL hash this subquery once, and only a constant
                        // organization lets it read this organization's appointments instead of the whole table.
                        ->where('appointments.organization_id', $membership->organization_id)
                        ->where('appointments.clinician_membership_id', $membership->id);
                });
        });
    }

    /** The same rule for one record (the policy's question). */
    public static function allows(Client $client, OrganizationMembership $membership): bool
    {
        $permissions = app(PermissionResolver::class);

        if ($client->organization_id !== $membership->organization_id || ! $membership->isActive()) {
            return false;
        }

        if ($permissions->membershipHas($membership, self::VIEW_ALL)) {
            return true;
        }

        if (! $permissions->membershipHas($membership, self::VIEW)) {
            return false;
        }

        if ($client->primary_clinician_membership_id === $membership->id) {
            return true;
        }

        return self::apply(Client::query()->whereKey($client->getKey()), $membership)->exists();
    }

    /** Sees every client (no per-record restriction). */
    public static function seesAll(OrganizationMembership $membership): bool
    {
        return $membership->isActive() && app(PermissionResolver::class)->membershipHas($membership, self::VIEW_ALL);
    }

    /** Holds either client-viewing permission at all (and is an active member). */
    public static function hasAnyAccess(OrganizationMembership $membership): bool
    {
        $permissions = app(PermissionResolver::class);

        return $membership->isActive()
            && ($permissions->membershipHas($membership, self::VIEW_ALL) || $permissions->membershipHas($membership, self::VIEW));
    }
}
