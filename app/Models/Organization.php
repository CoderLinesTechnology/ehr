<?php

namespace App\Models;

use App\Domain\Platform\OrganizationStatus;
use App\Domain\Saas\SubscriptionStatus;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The tenant. Status is written only by ChangeOrganizationStatus.
 */
#[Fillable([
    'name', 'legal_name', 'email', 'phone', 'website',
    'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country_code',
    'timezone', 'currency', 'locale',
])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'status_changed_at' => 'immutable_datetime',
            'onboarding_completed_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<OrganizationStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrganizationStatusHistory::class)->latest('occurred_at');
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return HasOne<Subscription, $this> */
    public function liveSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->whereIn('status', SubscriptionStatus::LIVE);
    }

    /** @return HasMany<OrganizationEntitlement, $this> */
    public function entitlementOverrides(): HasMany
    {
        return $this->hasMany(OrganizationEntitlement::class);
    }

    public function allowsAccess(): bool
    {
        return $this->status->allowsAccess();
    }

    public function displayAddress(): string
    {
        return collect([$this->address_line1, $this->address_line2, $this->city, $this->region, $this->postal_code])
            ->filter()->implode(', ');
    }
}
