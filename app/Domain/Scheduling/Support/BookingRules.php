<?php

namespace App\Domain\Scheduling\Support;

use App\Domain\Clients\ClientStatus;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Scheduling\Modality;
use App\Domain\Shared\DomainException;
use App\Models\Client;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * The checks every booking (new, rescheduled, or a change of place) must
 * pass, with messages that are safe to show the person booking.
 */
final class BookingRules
{
    public function assertClientBookable(Client $client): void
    {
        if ($client->status === ClientStatus::Archived || $client->archived_at !== null) {
            throw new DomainException('This client is archived. Restore the client before booking an appointment.', 'client_archived', 'client_id');
        }
    }

    public function assertServiceBookable(Service $service): void
    {
        if (! $service->is_active) {
            throw new DomainException('This service is no longer offered.', 'service_inactive', 'service_id');
        }
    }

    public function isBookableClinician(OrganizationMembership $clinician): bool
    {
        return $clinician->status === MembershipStatus::Active && $clinician->is_provider === true;
    }

    public function assertClinicianProvides(OrganizationMembership $clinician, Service $service): void
    {
        if (! $this->isBookableClinician($clinician)) {
            throw new DomainException('This clinician cannot be booked.', 'clinician_unavailable', 'clinician_membership_id');
        }

        $provides = DB::table('service_providers')
            ->where('organization_id', $service->organization_id)
            ->where('service_id', $service->id)
            ->where('membership_id', $clinician->id)
            ->exists();

        if (! $provides) {
            throw new DomainException('This clinician does not provide the selected service.', 'service_not_provided', 'clinician_membership_id');
        }
    }

    /**
     * Where the appointment takes place: the (validated) location for an
     * in-person appointment, null for telehealth (a location passed with a
     * telehealth booking is ignored — telehealth has no location).
     */
    public function placeFor(Service $service, Modality $modality, ?Location $location): ?Location
    {
        if (! $service->allows($modality)) {
            throw new DomainException(
                $modality === Modality::InPerson ? 'This service is not offered in person.' : 'This service is not offered by telehealth.',
                'modality_not_allowed',
                'modality',
            );
        }

        if ($modality === Modality::Telehealth) {
            return null;
        }

        if ($location === null) {
            throw new DomainException('Choose a location for an in-person appointment.', 'location_required', 'location_id');
        }

        if (! $location->is_active) {
            throw new DomainException('This location is closed.', 'location_inactive', 'location_id');
        }

        if (! $this->serviceOfferedAt($service, $location)) {
            throw new DomainException('This service is not offered at that location.', 'location_not_allowed', 'location_id');
        }

        return $location;
    }

    /** Display timezone snapshot: the location's, else the organization's. */
    public function timezoneFor(?Location $location, Organization $organization): string
    {
        return $location?->timezone ?? $organization->timezone;
    }

    /** A service with no locations attached is offered at every active location. */
    private function serviceOfferedAt(Service $service, Location $location): bool
    {
        $row = DB::table('service_locations')
            ->where('organization_id', $service->organization_id)
            ->where('service_id', $service->id)
            ->selectRaw('count(*) AS attached, count(*) FILTER (WHERE location_id = ?) AS here', [$location->id])
            ->first();

        return (int) $row->attached === 0 || (int) $row->here > 0;
    }
}
