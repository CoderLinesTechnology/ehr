<?php

namespace App\Domain\Organization;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Shared\DomainException;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * Activates or deactivates a location. Appointments and clients reference locations, so a
 * location is never deleted: deactivating hides it from new bookings and keeps history intact.
 * Activating counts against `max_locations`.
 */
final class ChangeLocationStatus
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly EntitlementService $entitlements,
        private readonly AuditLogger $audit,
        private readonly RefreshOnboardingStatus $onboarding,
    ) {}

    /** @throws DomainException */
    public function __invoke(Location $location, bool $active): Location
    {
        $this->guard->requirePermission('locations.manage');
        $this->guard->assertInOrganization($location);
        $organization = $this->guard->organization();

        DB::transaction(function () use ($location, $active, $organization) {
            $this->guard->lockOrganization();
            $locked = Location::query()->whereKey($location->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_active === $active) {
                return;
            }

            if ($active) {
                $this->entitlements->assertWithinLimit($organization, FeatureRegistry::MAX_LOCATIONS, Location::query()->active()->count());
            }

            $locked->forceFill(['is_active' => $active])->save();

            $this->audit->record(
                $active ? 'location.activated' : 'location.deactivated',
                $locked,
                before: ['is_active' => ! $active],
                after: ['is_active' => $active],
                summary: ($active ? 'Activated' : 'Deactivated')." the location {$locked->name}",
            );

            ($this->onboarding)($organization);
            $location->setRawAttributes($locked->getAttributes(), true);
        });

        return $location;
    }
}
