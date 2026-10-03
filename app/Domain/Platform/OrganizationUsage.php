<?php

namespace App\Domain\Platform;

use App\Domain\Clients\ClientStatus;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Shared\RecordEnvironment;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate COUNTS of what an organization uses, for the platform console.
 * Never rows: platform screens show how much, not who or what. The queries
 * name the organization explicitly (they are plain aggregates, outside the
 * tenant scope on purpose) and each is served by an organization_id index.
 */
final class OrganizationUsage
{
    /**
     * Staff seats mean active plus invited memberships (the definition the
     * max_staff limit uses); clients are active LIVE clients only (demo data
     * never counts); locations are active ones.
     *
     * @return array{staff: int, clients: int, locations: int, appointments_30d: int}
     */
    public function __invoke(Organization $organization): array
    {
        $now = now();

        $row = DB::selectOne(
            'SELECT
                (SELECT count(*) FROM organization_memberships WHERE organization_id = :o1 AND status IN (:active, :invited)) AS staff,
                (SELECT count(*) FROM clients WHERE organization_id = :o2 AND record_environment = :live AND status = :client_active) AS clients,
                (SELECT count(*) FROM locations WHERE organization_id = :o3 AND is_active) AS locations,
                (SELECT count(*) FROM appointments WHERE organization_id = :o4 AND record_environment = :live2 AND starts_at >= :since AND starts_at < :until) AS appointments_30d',
            [
                'o1' => $organization->id, 'o2' => $organization->id, 'o3' => $organization->id, 'o4' => $organization->id,
                'active' => MembershipStatus::Active->value, 'invited' => MembershipStatus::Invited->value,
                'live' => RecordEnvironment::Live->value, 'live2' => RecordEnvironment::Live->value,
                'client_active' => ClientStatus::Active->value,
                'since' => $now->subDays(30), 'until' => $now,
            ],
        );

        return [
            'staff' => (int) $row->staff,
            'clients' => (int) $row->clients,
            'locations' => (int) $row->locations,
            'appointments_30d' => (int) $row->appointments_30d,
        ];
    }

    /**
     * Staff seat counts for a page of organizations in one query (no N+1).
     *
     * @param  list<string>  $organizationIds
     * @return array<string, int> organization id => seats
     */
    public function staffCounts(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        return DB::table('organization_memberships')
            ->whereIn('organization_id', $organizationIds)
            ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Invited->value])
            ->groupBy('organization_id')
            ->selectRaw('organization_id, count(*) AS seats')
            ->pluck('seats', 'organization_id')
            ->map(fn ($seats) => (int) $seats)
            ->all();
    }
}
