<?php

namespace App\Domain\Saas;

use App\Domain\Shared\DomainException;
use App\Models\Organization;
use App\Models\Subscription;

/**
 * Shared by the actions that change an organization's live subscription. The
 * partial unique index guarantees at most one live row, so locking it is the
 * mutex for every change to the arrangement.
 */
trait LocksLiveSubscription
{
    private function lockLiveSubscription(Organization $organization): Subscription
    {
        return Subscription::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', SubscriptionStatus::LIVE)
            ->lockForUpdate()
            ->first()
            ?? throw new DomainException('This organization has no live subscription. Start one first.', 'no_live_subscription');
    }
}
