<?php

namespace App\Models;

use App\Domain\Scheduling\AvailabilityModality;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Domain\Tenancy\ScopesTenantPivots;
use Database\Factories\AvailabilityRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A recurring weekly window when a clinician can be booked. Times are
 * wall-clock in the location's timezone (organization timezone when the
 * rule has no location). effective_* are business dates (Y-m-d strings).
 */
#[Fillable([
    'membership_id', 'location_id', 'weekday', 'start_time', 'end_time', 'modality',
    'repeat_every_weeks', 'effective_from', 'effective_until', 'is_bookable_online', 'is_active',
])]
class AvailabilityRule extends Model
{
    /** @use HasFactory<AvailabilityRuleFactory> */
    use BelongsToOrganization, ScopesTenantPivots, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'repeat_every_weeks' => 'integer',
            'modality' => AvailabilityModality::class,
            'is_bookable_online' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<OrganizationMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'membership_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Empty = every service the clinician provides. @return BelongsToMany<Service, $this> */
    public function services(): BelongsToMany
    {
        return $this->tenantPivot($this->belongsToMany(Service::class, 'availability_rule_services', 'availability_rule_id', 'service_id'));
    }
}
