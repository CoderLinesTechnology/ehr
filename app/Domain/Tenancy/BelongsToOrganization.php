<?php

namespace App\Domain\Tenancy;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every tenant-owned model: queries are confined to the current
 * organization and new rows are stamped with it.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $context = app(TenantContext::class);
            $current = $context->id();

            if ($model->organization_id === null) {
                $model->organization_id = $current ?? throw new MissingTenantContext;

                return;
            }

            if ($context->bypassing()) {
                return;
            }

            if ($current === null) {
                throw new MissingTenantContext;
            }

            if ($model->organization_id !== $current) {
                throw new TenantMismatch('Refusing to create a row for another organization.');
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('organization_id')) {
                throw new TenantMismatch('organization_id is immutable.');
            }
            // A demo record can never become live data, nor the reverse.
            if (array_key_exists('record_environment', $model->getAttributes()) && $model->isDirty('record_environment')) {
                throw new \LogicException('record_environment is immutable.');
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Explicit, reviewable escape hatch for cross-tenant platform queries. */
    public static function acrossTenants(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }
}
