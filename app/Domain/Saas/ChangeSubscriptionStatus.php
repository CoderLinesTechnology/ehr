<?php

namespace App\Domain\Saas;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The one way an organization's live subscription changes status: locks the
 * subscription, checks the transition against SubscriptionTransitions, stamps
 * the milestone columns, writes insert-only history, audits, and recomputes the
 * organization's entitlements (a cancelled or expired subscription ends them).
 *
 * Status columns are written here with forceFill and nowhere else.
 */
final class ChangeSubscriptionStatus
{
    use LocksLiveSubscription;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EntitlementService $entitlements,
    ) {}

    public function __invoke(
        Organization $organization,
        SubscriptionStatus $to,
        ?User $actor,
        string $reason,
        ?CarbonInterface $graceEndsAt = null,
    ): Subscription {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A reason is required to change a subscription\'s status.', 'reason_required', 'reason');
        }
        if (mb_strlen($reason) > 500) {
            throw new DomainException('The reason may not be longer than 500 characters.', 'reason_too_long', 'reason');
        }

        // See SetEntitlementOverride: instants are normalised to UTC before they are stored.
        $graceEndsAt = $graceEndsAt === null ? null : CarbonImmutable::instance($graceEndsAt)->utc();

        if ($to === SubscriptionStatus::Grace && $graceEndsAt !== null && ! $graceEndsAt->isFuture()) {
            throw new DomainException('The end of the grace period must be in the future.', 'grace_in_past', 'grace_ends_at');
        }

        $subscription = DB::transaction(function () use ($organization, $to, $actor, $reason, $graceEndsAt) {
            $subscription = $this->lockLiveSubscription($organization);
            $from = $subscription->status;

            if ($from === $to) {
                return $subscription;
            }

            if (! SubscriptionTransitions::canTransition($from, $to)) {
                throw new DomainException(
                    "A subscription that is {$from->label()} cannot be changed to {$to->label()}.",
                    'invalid_transition',
                );
            }

            $now = now();
            $changes = ['status' => $to];

            if ($to === SubscriptionStatus::Cancelled) {
                $changes['cancelled_at'] = $now;
                $changes['ended_at'] = $now;
            } elseif ($to === SubscriptionStatus::Expired) {
                $changes['ended_at'] = $now;
            }

            if ($to === SubscriptionStatus::Grace) {
                $changes['grace_ends_at'] = $graceEndsAt;
            } elseif ($subscription->grace_ends_at !== null) {
                $changes['grace_ends_at'] = null;
            }

            // A trial that converts starts its first paid period now, the same
            // arrangement StartSubscription gives a subscription sold without a trial.
            if ($from === SubscriptionStatus::Trialing && $to === SubscriptionStatus::Active) {
                $changes['current_period_starts_at'] = $now;
                $changes['current_period_ends_at'] = $subscription->billing_interval === 'year' ? $now->copy()->addYear() : $now->copy()->addMonth();
            }

            $subscription->forceFill($changes)->save();

            $metadata = array_filter([
                'grace_ends_at' => $to === SubscriptionStatus::Grace ? $graceEndsAt?->toIso8601String() : null,
                'current_period_ends_at' => isset($changes['current_period_ends_at']) ? $changes['current_period_ends_at']->toIso8601String() : null,
            ]);

            $history = new SubscriptionHistory;
            $history->forceFill([
                'organization_id' => $subscription->organization_id,
                'subscription_id' => $subscription->id,
                'event' => 'status_changed',
                'from_plan_id' => $subscription->plan_id,
                'to_plan_id' => $subscription->plan_id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'reason' => $reason,
                'actor_user_id' => $actor?->id,
                'metadata' => $metadata === [] ? null : $metadata,
                'occurred_at' => $now,
            ])->save();

            $this->audit->record(
                'subscription.status_changed',
                subject: $subscription,
                before: ['status' => $from->value],
                after: ['status' => $to->value] + $metadata,
                metadata: ['reason' => $reason],
                summary: "Subscription {$from->label()} → {$to->label()}",
                context: AuditContext::Platform,
            );

            return $subscription;
        });

        $organization->unsetRelation('liveSubscription');
        $this->entitlements->flush($organization);

        return $subscription;
    }
}
