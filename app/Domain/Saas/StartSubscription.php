<?php

namespace App\Domain\Saas;

use App\Domain\Shared\DomainException;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Opens an organization's subscription on a plan, snapshotting the plan's
 * price/currency/interval. The partial unique index guarantees there is never
 * more than one live subscription per organization.
 */
final class StartSubscription
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function __invoke(Organization $organization, Plan $plan, bool $trial, ?User $actor, ?string $reason = null): Subscription
    {
        if (! $plan->is_active) {
            throw new DomainException("The {$plan->name} plan is not available.", 'plan_inactive');
        }

        return DB::transaction(function () use ($organization, $plan, $trial, $actor, $reason) {
            $trialing = $trial && $plan->trial_days > 0;
            $now = now();

            $subscription = new Subscription;
            $subscription->forceFill([
                'organization_id' => $organization->id,
                'plan_id' => $plan->id,
                'status' => $trialing ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
                'price_minor' => $plan->price_minor,
                'currency' => $plan->currency,
                'billing_interval' => $plan->billing_interval,
                'trial_ends_at' => $trialing ? $now->copy()->addDays($plan->trial_days) : null,
                'current_period_starts_at' => $now,
                'current_period_ends_at' => $trialing
                    ? $now->copy()->addDays($plan->trial_days)
                    : ($plan->billing_interval === 'year' ? $now->copy()->addYear() : $now->copy()->addMonth()),
                'provider' => 'manual',
            ])->save();

            $history = new SubscriptionHistory;
            $history->forceFill([
                'organization_id' => $organization->id,
                'subscription_id' => $subscription->id,
                'event' => 'started',
                'to_plan_id' => $plan->id,
                'to_status' => $subscription->status->value,
                'reason' => $reason,
                'actor_user_id' => $actor?->id,
                'occurred_at' => $now,
            ])->save();

            $organization->unsetRelation('liveSubscription');
            $this->entitlements->flush($organization);

            return $subscription;
        });
    }
}
