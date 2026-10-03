<?php

namespace App\Domain\Organization;

use App\Domain\Audit\AuditLogger;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Stamps `organizations.onboarding_completed_at` the first time the organization has a complete
 * profile, an active location, an active service and a provider with availability. It never
 * un-completes (deactivating a location later does not bring the setup banner back) and it
 * stamps once: the row is locked and re-read, so a second call, a concurrent call or a call
 * with an out-of-date instance leaves the first timestamp and the single audit entry alone.
 *
 * Call it after anything that could finish setup (profile, location, service, availability).
 * Safe to call as often as needed: an organization already known to be complete costs no query.
 */
final class RefreshOnboardingStatus
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return bool whether onboarding is complete */
    public function __invoke(Organization $organization): bool
    {
        if ($organization->onboarding_completed_at !== null) {
            return true;
        }

        if (! OnboardingChecklist::isComplete(OnboardingChecklist::for($organization))) {
            return false;
        }

        DB::transaction(function () use ($organization) {
            $locked = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();

            if ($locked->onboarding_completed_at === null) {
                $locked->forceFill(['onboarding_completed_at' => now()])->save();

                $this->audit->record(
                    'organization.onboarding_completed',
                    $locked,
                    summary: 'Initial setup completed',
                );
            }

            $organization->forceFill(['onboarding_completed_at' => $locked->onboarding_completed_at])->syncOriginalAttribute('onboarding_completed_at');
        });

        return true;
    }
}
