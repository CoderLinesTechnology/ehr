<?php

namespace App\Domain\Resources;

use App\Domain\Identity\PermissionResolver;
use App\Domain\Saas\EntitlementService;
use App\Domain\Scheduling\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use Carbon\CarbonImmutable;

/**
 * The viewer's own next appointment(s) as clinician, for the Resources right rail. Empty without the
 * calendar module or without appointment permissions. One query, never more than $limit rows.
 */
final class UpcomingForViewer
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly EntitlementService $entitlements,
    ) {}

    public function allowed(OrganizationMembership $membership, Organization $organization): bool
    {
        return $membership->isActive()
            && ($this->permissions->membershipHas($membership, 'appointments.view') || $this->permissions->membershipHas($membership, 'appointments.view_all'))
            && $this->entitlements->allows($organization, 'calendar');
    }

    /** @return list<array{id: string, service: string, client: string, startsAt: CarbonImmutable, endsAt: CarbonImmutable, timezone: string}> */
    public function __invoke(OrganizationMembership $membership, Organization $organization, int $limit = 1, ?CarbonImmutable $now = null): array
    {
        if (! $this->allowed($membership, $organization)) {
            return [];
        }
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();

        return Appointment::query()
            ->join('clients', fn ($join) => $join->on('clients.id', '=', 'appointments.client_id')->on('clients.organization_id', '=', 'appointments.organization_id'))
            ->join('services', fn ($join) => $join->on('services.id', '=', 'appointments.service_id')->on('services.organization_id', '=', 'appointments.organization_id'))
            ->where('appointments.clinician_membership_id', $membership->id)
            ->whereIn('appointments.status', AppointmentStatus::UPCOMING)
            ->where('appointments.starts_at', '>=', $now->toDateTimeString())
            ->orderBy('appointments.starts_at')->orderBy('appointments.id')
            ->limit($limit)
            ->toBase()
            ->get(['appointments.id', 'appointments.starts_at', 'appointments.ends_at', 'appointments.timezone', 'clients.first_name', 'clients.last_name', 'services.name as service_name'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'service' => $row->service_name,
                'client' => trim($row->first_name.' '.$row->last_name),
                'startsAt' => CarbonImmutable::parse($row->starts_at, 'UTC'),
                'endsAt' => CarbonImmutable::parse($row->ends_at, 'UTC'),
                'timezone' => $row->timezone,
            ])->all();
    }
}
