<?php

namespace App\Models;

use App\Domain\Tenancy\MissingTenantContext;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Organization roles (organization_id set) and platform roles (organization_id
 * NULL) share one table; scope and the composite FKs keep them apart.
 */
#[Fillable(['name', 'description'])]
class Role extends Model
{
    use HasUuids;

    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_PLATFORM = 'platform';

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    /** @return HasMany<RolePermission, $this> */
    public function permissionGrants(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /** @return BelongsToMany<OrganizationMembership, $this> */
    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(OrganizationMembership::class, 'membership_roles', 'role_id', 'membership_id');
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        $grants = $this->relationLoaded('permissionGrants') ? $this->permissionGrants : $this->permissionGrants()->get();

        return $grants->pluck('permission_key')->all();
    }

    public function scopeForOrganization(Builder $query, string $organizationId): Builder
    {
        return $query->where('scope', self::SCOPE_ORGANIZATION)->where('organization_id', $organizationId);
    }

    public function scopePlatform(Builder $query): Builder
    {
        return $query->where('scope', self::SCOPE_PLATFORM)->whereNull('organization_id');
    }

    /** Route binding inside the staff app resolves only the current organization's roles. */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $organizationId = app(TenantContext::class)->id() ?? throw new MissingTenantContext;
        $field ??= $this->getRouteKeyName();

        if ($field === $this->getKeyName() && ! Str::isUuid((string) $value)) {
            return null; // malformed id → 404, never a database error
        }

        return static::query()->forOrganization($organizationId)->where($field, $value)->first();
    }

    public function isPlatform(): bool
    {
        return $this->scope === self::SCOPE_PLATFORM;
    }
}
