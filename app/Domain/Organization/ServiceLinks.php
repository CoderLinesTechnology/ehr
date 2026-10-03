<?php

namespace App\Domain\Organization;

use App\Domain\Shared\DomainException;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who provides a service and where it is offered.
 *
 * Providers must be active clinicians (provider flag) of the same organization; locations must be
 * active locations of the same organization. An empty location list means "every location".
 * Ids from another organization are refused here and, independently, by the composite foreign
 * keys on service_providers / service_locations.
 *
 * Links to people or places that are no longer eligible (a deactivated clinician, a closed location)
 * are left exactly as they were: saving the form must not silently forget them, because
 * reactivating the clinician should bring the service back with them.
 */
final class ServiceLinks
{
    /**
     * @param  list<string>  $providerIds
     * @param  list<string>  $locationIds
     * @return array{providers: array{0: list<string>, 1: list<string>}, locations: array{0: list<string>, 1: list<string>}} [before, after] names, for the audit entry
     *
     * @throws DomainException
     */
    public function sync(Service $service, array $providerIds, array $locationIds): array
    {
        $providerIds = $this->ids($providerIds, 'providers');
        $locationIds = $this->ids($locationIds, 'locations');

        $eligibleProviders = OrganizationMembership::query()->providers()->pluck('id')->all();
        $eligibleLocations = Location::query()->active()->pluck('id')->all();

        if (array_diff($providerIds, $eligibleProviders) !== []) {
            throw new DomainException('Choose providers from your active clinicians.', 'invalid_provider', 'providers');
        }
        if (array_diff($locationIds, $eligibleLocations) !== []) {
            throw new DomainException('Choose locations from your active locations.', 'invalid_location', 'locations');
        }

        $currentProviders = DB::table('service_providers')->where('service_id', $service->id)->pluck('membership_id')->all();
        $currentLocations = DB::table('service_locations')->where('service_id', $service->id)->pluck('location_id')->all();

        $finalProviders = array_values(array_unique([...$providerIds, ...array_diff($currentProviders, $eligibleProviders)]));
        $finalLocations = array_values(array_unique([...$locationIds, ...array_diff($currentLocations, $eligibleLocations)]));

        $service->providers()->sync($finalProviders);
        $service->locations()->sync($finalLocations);

        return [
            'providers' => [$this->memberNames($currentProviders), $this->memberNames($finalProviders)],
            'locations' => [$this->locationNames($currentLocations), $this->locationNames($finalLocations)],
        ];
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<string>
     */
    private function ids(array $ids, string $field): array
    {
        $ids = array_values(array_unique(array_map('strval', array_filter($ids, 'is_scalar'))));

        foreach ($ids as $id) {
            if (! Str::isUuid($id)) {
                throw new DomainException('One of the selected options is not available.', 'invalid_option', $field);
            }
        }

        return $ids;
    }

    /** @return list<string> */
    private function memberNames(array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        return DB::table('organization_memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->whereIn('m.id', $membershipIds)
            ->orderBy('u.name')
            ->pluck('u.name')
            ->all();
    }

    /** @return list<string> */
    private function locationNames(array $locationIds): array
    {
        return $locationIds === [] ? [] : Location::query()->whereIn('id', $locationIds)->orderBy('name')->pluck('name')->all();
    }
}
