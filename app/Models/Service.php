<?php

namespace App\Models;

use App\Domain\Scheduling\Modality;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Domain\Tenancy\ScopesTenantPivots;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Service catalogue entry. Price changes never touch existing appointments:
 * each appointment snapshots price_minor/currency at booking.
 */
// Price, currency and active status are not mass-assignable: SaveService /
// ChangeServiceStatus write them with forceFill.
#[Fillable([
    'name', 'description', 'code', 'duration_minutes',
    'allows_in_person', 'allows_telehealth', 'is_bookable_online', 'billing_behavior',
    'requires_documentation', 'cancellation_notice_hours', 'late_cancellation_fee_minor',
    'no_show_fee_minor', 'color', 'sort',
])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use BelongsToOrganization, ScopesTenantPivots, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price_minor' => 'integer',
            'late_cancellation_fee_minor' => 'integer',
            'no_show_fee_minor' => 'integer',
            'cancellation_notice_hours' => 'integer',
            'allows_in_person' => 'boolean',
            'allows_telehealth' => 'boolean',
            'is_bookable_online' => 'boolean',
            'requires_documentation' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<OrganizationMembership, $this> */
    public function providers(): BelongsToMany
    {
        return $this->tenantPivot($this->belongsToMany(OrganizationMembership::class, 'service_providers', 'service_id', 'membership_id'));
    }

    /** @return BelongsToMany<Location, $this> */
    public function locations(): BelongsToMany
    {
        return $this->tenantPivot($this->belongsToMany(Location::class, 'service_locations', 'service_id', 'location_id'));
    }

    public function allows(Modality $modality): bool
    {
        return $modality === Modality::InPerson ? $this->allows_in_person : $this->allows_telehealth;
    }

    /** @return list<Modality> */
    public function modalities(): array
    {
        return array_values(array_filter([
            $this->allows_in_person ? Modality::InPerson : null,
            $this->allows_telehealth ? Modality::Telehealth : null,
        ]));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
