<?php

namespace App\Models;

use App\Domain\Saas\SubscriptionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SaaS-domain record about an organization (not tenant clinical data).
 * Written only by the Saas domain actions.
 */
class Subscription extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'price_minor' => 'integer',
            'cancel_at_period_end' => 'boolean',
            'trial_ends_at' => 'immutable_datetime',
            'current_period_starts_at' => 'immutable_datetime',
            'current_period_ends_at' => 'immutable_datetime',
            'grace_ends_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<SubscriptionHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(SubscriptionHistory::class)->latest('occurred_at');
    }
}
