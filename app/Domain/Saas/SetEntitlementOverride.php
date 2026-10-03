<?php

namespace App\Domain\Saas;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Organization;
use App\Models\OrganizationEntitlement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Sets (or replaces) one organization's override of a plan feature or limit.
 * One row per (organization, feature): setting again replaces the old override,
 * reason and expiry included. A boolean feature is switched on or off; a limit
 * feature takes a number or "unlimited" (stored as NULL). An override with an
 * expiry stops applying by itself, with no job needed (EntitlementService
 * ignores expired rows).
 */
final class SetEntitlementOverride
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EntitlementService $entitlements,
    ) {}

    public function __invoke(
        Organization $organization,
        string $featureKey,
        ?bool $enabled,
        ?int $limit,
        bool $unlimited,
        string $reason,
        ?CarbonInterface $expiresAt,
        ?User $actor,
    ): OrganizationEntitlement {
        if (! in_array($featureKey, FeatureRegistry::keys(), true)) {
            throw new DomainException('That feature does not exist.', 'unknown_feature', 'feature_key');
        }

        $isLimit = FeatureRegistry::isLimit($featureKey);

        if ($isLimit) {
            if (! $unlimited && $limit === null) {
                throw new DomainException('Enter a limit, or choose unlimited.', 'value_required', 'limit_value');
            }
            if (! $unlimited && $limit < 0) {
                throw new DomainException('A limit cannot be negative.', 'invalid_limit', 'limit_value');
            }
        } elseif ($enabled === null) {
            throw new DomainException('Choose whether the feature is on or off.', 'value_required', 'enabled');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A reason is required to override an entitlement.', 'reason_required', 'reason');
        }
        if (mb_strlen($reason) > 500) {
            throw new DomainException('The reason may not be longer than 500 characters.', 'reason_too_long', 'reason');
        }

        if ($expiresAt !== null && ! $expiresAt->isFuture()) {
            throw new DomainException('The expiry must be in the future.', 'expiry_in_past', 'expires_at');
        }

        // Eloquent writes a Carbon instant without its offset and the database
        // session speaks UTC: an instant in any other zone would be stored hours off.
        $expiresAt = $expiresAt === null ? null : CarbonImmutable::instance($expiresAt)->utc();

        $override = DB::transaction(function () use ($organization, $featureKey, $isLimit, $enabled, $limit, $unlimited, $reason, $expiresAt, $actor) {
            // The organization row serialises every writer of its entitlements,
            // so two administrators cannot both insert the same (organization, feature).
            Organization::query()->lockForUpdate()->findOrFail($organization->id);

            $row = OrganizationEntitlement::query()
                ->where('organization_id', $organization->id)
                ->where('feature_key', $featureKey)
                ->first();

            $before = $row !== null ? $this->describe($row, $isLimit) : null;
            $row ??= new OrganizationEntitlement;

            $row->forceFill([
                'organization_id' => $organization->id,
                'feature_key' => $featureKey,
                'enabled' => $isLimit ? null : $enabled,
                'limit_value' => $isLimit && ! $unlimited ? $limit : null,
                'reason' => $reason,
                'expires_at' => $expiresAt,
                'granted_by_user_id' => $actor?->id,
            ])->save();

            $after = $this->describe($row, $isLimit);
            $name = $this->featureName($featureKey);

            $this->audit->record(
                'entitlement.override_set',
                subject: $row,
                before: $before,
                after: $after,
                metadata: ['feature' => $featureKey, 'reason' => $reason],
                summary: "{$organization->name}: {$name} set to {$after['value']}".($expiresAt !== null ? ' until '.$expiresAt->toDateString() : ''),
                context: AuditContext::Platform,
            );

            return $row;
        });

        $this->entitlements->flush($organization);

        return $override;
    }

    /** @return array{value: int|string, expires_at: ?string} */
    private function describe(OrganizationEntitlement $row, bool $isLimit): array
    {
        return [
            'value' => $isLimit ? ($row->limit_value ?? 'unlimited') : ($row->enabled ? 'on' : 'off'),
            'expires_at' => $row->expires_at?->toIso8601String(),
        ];
    }

    private function featureName(string $featureKey): string
    {
        foreach (FeatureRegistry::definitions() as $definition) {
            if ($definition['key'] === $featureKey) {
                return $definition['name'];
            }
        }

        return $featureKey;
    }
}
