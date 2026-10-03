<?php

namespace App\Domain\Organization;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Shared\DomainException;
use App\Models\Location;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a location (no $location given) or edits one (a location of the current organization).
 *
 *  - A new location is active, so it counts against `max_locations` (active locations). The count is
 *    taken under a lock on the organization row, so two simultaneous creations cannot both slip
 *    under the limit.
 *  - Whether a location is active is NOT editable here (see ChangeLocationStatus): activation is
 *    limit-checked and audited on its own. Locations are never deleted — appointments reference them.
 *  - Names are unique per organization, ignoring case.
 *
 * Audit: `location.created` (the saved values) or `location.updated` (before/after of what changed).
 */
final class SaveLocation
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly EntitlementService $entitlements,
        private readonly AuditLogger $audit,
        private readonly RefreshOnboardingStatus $onboarding,
    ) {}

    /**
     * @param  array<string, mixed>  $input  name, address_line1/2, city, region, postal_code, country_code, phone, email,
     *                                       timezone (defaults to the organization's), business_hours (storage format, see BusinessHours)
     *
     * @throws DomainException
     */
    public function __invoke(array $input, ?Location $location = null): Location
    {
        $this->guard->requirePermission('locations.manage');
        $organization = $this->guard->organization();

        if ($location !== null) {
            $this->guard->assertInOrganization($location);
        }

        $attributes = LocationData::attributes($input, $organization->timezone);

        return DB::transaction(function () use ($organization, $attributes, $location) {
            $this->guard->lockOrganization();

            $locked = $location === null
                ? null
                : Location::query()->whereKey($location->id)->lockForUpdate()->firstOrFail();

            $taken = Location::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower($attributes['name'])])
                ->when($locked, fn ($query) => $query->whereKeyNot($locked->id))
                ->exists();
            if ($taken) {
                throw $this->nameTaken();
            }

            if ($locked === null) {
                $this->entitlements->assertWithinLimit($organization, FeatureRegistry::MAX_LOCATIONS, Location::query()->active()->count());

                $saved = new Location;
                $saved->fill($attributes);
                $saved->forceFill(['is_active' => true]);
                $this->save($saved);

                $this->audit->record(
                    'location.created',
                    $saved,
                    after: $attributes + ['is_active' => true],
                    summary: "Added the location {$saved->name}",
                );
            } else {
                $saved = $locked;
                $saved->fill($attributes);
                [$before, $after] = AuditDiff::of($saved);
                $this->save($saved);

                if ($after !== []) {
                    $this->audit->record('location.updated', $saved, $before, $after, summary: "Updated the location {$saved->name}");
                }

                $location->setRawAttributes($saved->getAttributes(), true);
            }

            ($this->onboarding)($organization);

            return $location ?? $saved;
        });
    }

    private function save(Location $location): void
    {
        try {
            $location->save();
        } catch (UniqueConstraintViolationException) {
            throw $this->nameTaken();
        }
    }

    private function nameTaken(): DomainException
    {
        return new DomainException('You already have a location with that name.', 'location_name_taken', 'name');
    }
}
