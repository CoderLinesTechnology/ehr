<?php

namespace App\Domain\Saas;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Organization;
use App\Models\OrganizationEntitlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Removes an organization's override so the plan's own value applies again. */
final class RemoveEntitlementOverride
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EntitlementService $entitlements,
    ) {}

    public function __invoke(Organization $organization, string $featureKey, ?User $actor, string $reason): void
    {
        if (! in_array($featureKey, FeatureRegistry::keys(), true)) {
            throw new DomainException('That feature does not exist.', 'unknown_feature', 'feature_key');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A reason is required to remove an override.', 'reason_required', 'reason');
        }
        if (mb_strlen($reason) > 500) {
            throw new DomainException('The reason may not be longer than 500 characters.', 'reason_too_long', 'reason');
        }

        DB::transaction(function () use ($organization, $featureKey, $reason) {
            Organization::query()->lockForUpdate()->findOrFail($organization->id);

            $row = OrganizationEntitlement::query()
                ->where('organization_id', $organization->id)
                ->where('feature_key', $featureKey)
                ->first();

            if ($row === null) {
                throw new DomainException('There is no override to remove for that feature.', 'no_override');
            }

            $isLimit = FeatureRegistry::isLimit($featureKey);
            $before = [
                'value' => $isLimit ? ($row->limit_value ?? 'unlimited') : ($row->enabled ? 'on' : 'off'),
                'expires_at' => $row->expires_at?->toIso8601String(),
            ];

            $row->delete();

            $this->audit->record(
                'entitlement.override_removed',
                subject: $row,
                before: $before,
                metadata: ['feature' => $featureKey, 'reason' => $reason],
                summary: "{$organization->name}: override of {$featureKey} removed",
                context: AuditContext::Platform,
            );
        });

        $this->entitlements->flush($organization);
    }
}
