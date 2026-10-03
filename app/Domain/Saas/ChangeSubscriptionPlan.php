<?php

namespace App\Domain\Saas;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moves an organization's live subscription to another plan. The new plan's
 * price, currency and billing interval are snapshotted onto the subscription
 * (so later edits to the plan never rewrite what this organization pays), the
 * change is recorded in the insert-only history and the audit log, and the
 * organization's entitlements are recomputed.
 *
 * Period dates are left as they are: there is no proration engine yet, and a
 * payment provider adapter will reconcile the period through this same action.
 */
final class ChangeSubscriptionPlan
{
    use LocksLiveSubscription;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EntitlementService $entitlements,
    ) {}

    public function __invoke(Organization $organization, Plan $plan, ?User $actor, string $reason): Subscription
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A reason is required to change an organization\'s plan.', 'reason_required', 'reason');
        }
        if (mb_strlen($reason) > 500) {
            throw new DomainException('The reason may not be longer than 500 characters.', 'reason_too_long', 'reason');
        }

        $subscription = DB::transaction(function () use ($organization, $plan, $actor, $reason) {
            $subscription = $this->lockLiveSubscription($organization);

            // A shared lock: many organizations may move onto the same plan at
            // once, but an edit of the plan (price, availability) must not land
            // between reading it and snapshotting it.
            /** @var Plan $target */
            $target = Plan::query()->sharedLock()->findOrFail($plan->id);

            if (! $target->is_active) {
                throw new DomainException("The {$target->name} plan is not available.", 'plan_inactive', 'plan_id');
            }
            if ($target->id === $subscription->plan_id) {
                throw new DomainException('The organization is already on that plan.', 'same_plan', 'plan_id');
            }

            /** @var Plan $from */
            $from = Plan::query()->findOrFail($subscription->plan_id);
            $before = $this->snapshot($from->key, $subscription->price_minor, $subscription->currency, $subscription->billing_interval);

            $subscription->forceFill([
                'plan_id' => $target->id,
                'price_minor' => $target->price_minor,
                'currency' => $target->currency,
                'billing_interval' => $target->billing_interval,
            ])->save();

            $after = $this->snapshot($target->key, $target->price_minor, $target->currency, $target->billing_interval);

            $history = new SubscriptionHistory;
            $history->forceFill([
                'organization_id' => $subscription->organization_id,
                'subscription_id' => $subscription->id,
                'event' => 'plan_changed',
                'from_plan_id' => $from->id,
                'to_plan_id' => $target->id,
                'from_status' => $subscription->status->value,
                'to_status' => $subscription->status->value,
                'reason' => $reason,
                'actor_user_id' => $actor?->id,
                'metadata' => ['from' => $before, 'to' => $after],
                'occurred_at' => now(),
            ])->save();

            $this->audit->record(
                'subscription.plan_changed',
                subject: $subscription,
                before: $before,
                after: $after,
                metadata: ['reason' => $reason],
                summary: "Plan changed from {$from->name} to {$target->name}",
                context: AuditContext::Platform,
            );

            return $subscription;
        });

        $organization->unsetRelation('liveSubscription');
        $this->entitlements->flush($organization);

        return $subscription;
    }

    /** @return array{plan: string, price_minor: int, currency: string, billing_interval: string} */
    private function snapshot(string $planKey, int $priceMinor, string $currency, string $interval): array
    {
        return ['plan' => $planKey, 'price_minor' => $priceMinor, 'currency' => $currency, 'billing_interval' => $interval];
    }
}
