<?php

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Tenant pivot tables (membership_roles, service_providers, …) carry their own
 * organization_id NOT NULL column. withPivotValue() stamps it on attach().
 *
 * Eager loading builds the relation on a blank model (organization_id null),
 * where withPivotValue() would throw — so it is applied only when the parent
 * has an organization. Reads stay correct either way: the composite foreign
 * keys guarantee a pivot row's organization equals its parent's.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait ScopesTenantPivots
{
    /**
     * @template TRelation of BelongsToMany
     *
     * @param  TRelation  $relation
     * @return TRelation
     */
    protected function tenantPivot(BelongsToMany $relation): BelongsToMany
    {
        $organizationId = $this->getAttributes()['organization_id'] ?? null;

        return $organizationId !== null ? $relation->withPivotValue('organization_id', $organizationId) : $relation;
    }
}
