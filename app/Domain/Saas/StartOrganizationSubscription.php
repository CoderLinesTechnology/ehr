<?php

namespace App\Domain\Saas;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Starts a subscription for an organization that has none live (its previous
 * one was cancelled or expired). Wraps StartSubscription with the guard and
 * the audit entry the platform console needs: StartSubscription itself only
 * writes history, and relies on the database index to refuse a second live row.
 */
final class StartOrganizationSubscription
{
    public function __construct(
        private readonly StartSubscription $start,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(Organization $organization, Plan $plan, bool $trial, ?User $actor, ?string $reason = null): Subscription
    {
        $reason = $reason !== null ? trim($reason) : null;
        $reason = $reason === '' ? null : $reason;
        if ($reason !== null && mb_strlen($reason) > 500) {
            throw new DomainException('The reason may not be longer than 500 characters.', 'reason_too_long', 'reason');
        }

        return DB::transaction(function () use ($organization, $plan, $trial, $actor, $reason) {
            // The organization row is the mutex: two administrators starting a
            // subscription at the same moment cannot both pass the check below.
            Organization::query()->lockForUpdate()->findOrFail($organization->id);

            $hasLive = Subscription::query()
                ->where('organization_id', $organization->id)
                ->whereIn('status', SubscriptionStatus::LIVE)
                ->exists();

            if ($hasLive) {
                throw new DomainException('This organization already has a live subscription. Change its plan or status instead.', 'subscription_exists');
            }

            $subscription = ($this->start)($organization, $plan, $trial, $actor, $reason ?? 'Started from the platform console');

            $this->audit->record(
                'subscription.started',
                subject: $subscription,
                after: [
                    'plan' => $plan->key,
                    'status' => $subscription->status->value,
                    'price_minor' => $subscription->price_minor,
                    'currency' => $subscription->currency,
                    'billing_interval' => $subscription->billing_interval,
                ],
                metadata: array_filter(['reason' => $reason]),
                summary: "Subscription started on the {$plan->name} plan",
                context: AuditContext::Platform,
            );

            return $subscription;
        });
    }
}
