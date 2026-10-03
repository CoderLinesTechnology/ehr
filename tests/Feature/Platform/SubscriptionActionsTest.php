<?php

namespace Tests\Feature\Platform;

use App\Domain\Platform\OrganizationStatus;
use App\Domain\Saas\ChangeSubscriptionPlan;
use App\Domain\Saas\ChangeSubscriptionStatus;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Saas\StartOrganizationSubscription;
use App\Domain\Saas\SubscriptionStatus;
use App\Domain\Saas\SubscriptionTransitions;
use App\Domain\Shared\DomainException;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;

class SubscriptionActionsTest extends PlatformTestCase
{
    private function plan(string $key): Plan
    {
        return Plan::query()->where('key', $key)->firstOrFail();
    }

    private function subscription(Organization $organization): Subscription
    {
        return Subscription::query()->where('organization_id', $organization->id)->orderByDesc('created_at')->firstOrFail();
    }

    private function history(Organization $organization): Collection
    {
        return SubscriptionHistory::query()->where('organization_id', $organization->id)->orderBy('occurred_at')->orderBy('id')->get();
    }

    // ── ChangeSubscriptionPlan ──────────────────────────────────────────

    #[Test]
    public function changing_the_plan_snapshots_the_new_price_and_writes_history_and_audit(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $starter = $this->plan('starter');
        $professional = $this->plan('professional');

        $subscription = app(ChangeSubscriptionPlan::class)($organization, $professional, $admin, 'Upgrade agreed by phone');

        $fresh = $subscription->fresh();
        $this->assertSame($professional->id, $fresh->plan_id);
        $this->assertSame(60000, $fresh->price_minor);
        $this->assertSame('GHS', $fresh->currency);
        $this->assertSame('month', $fresh->billing_interval);
        $this->assertSame(SubscriptionStatus::Active, $fresh->status, 'A plan change does not change the status.');

        $history = $this->history($organization)->last();
        $this->assertSame('plan_changed', $history->event);
        $this->assertSame($starter->id, $history->from_plan_id);
        $this->assertSame($professional->id, $history->to_plan_id);
        $this->assertSame('active', $history->from_status);
        $this->assertSame('active', $history->to_status);
        $this->assertSame('Upgrade agreed by phone', $history->reason);
        $this->assertSame($admin->id, $history->actor_user_id);
        // jsonb does not keep key order, so compare structurally.
        $this->assertEquals(['plan' => 'starter', 'price_minor' => 25000, 'currency' => 'GHS', 'billing_interval' => 'month'], $history->metadata['from']);
        $this->assertEquals(['plan' => 'professional', 'price_minor' => 60000, 'currency' => 'GHS', 'billing_interval' => 'month'], $history->metadata['to']);

        $audit = $this->audit('subscription.plan_changed');
        $this->assertSame('platform', $audit->context);
        $this->assertSame('subscription', $audit->subject_type);
        $this->assertSame($fresh->id, $audit->subject_id);
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame('starter', $audit->before['plan']);
        $this->assertSame(25000, $audit->before['price_minor']);
        $this->assertSame('professional', $audit->after['plan']);
        $this->assertSame(60000, $audit->after['price_minor']);
        $this->assertSame('Upgrade agreed by phone', $audit->metadata['reason']);
    }

    #[Test]
    public function changing_the_plan_recomputes_entitlements_in_the_same_request(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $entitlements = app(EntitlementService::class);

        // Warm the memoised answers first: they must not outlive the change.
        $this->assertFalse($entitlements->allows($organization, FeatureRegistry::PUBLIC_BOOKING));
        $this->assertSame(3, $entitlements->limit($organization, FeatureRegistry::MAX_STAFF));

        app(ChangeSubscriptionPlan::class)($organization, $this->plan('professional'), $admin, 'Upgrade');

        $this->assertTrue($entitlements->allows($organization, FeatureRegistry::PUBLIC_BOOKING));
        $this->assertSame(15, $entitlements->limit($organization, FeatureRegistry::MAX_STAFF));
    }

    #[Test]
    public function a_plan_change_needs_a_reason(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;

        try {
            app(ChangeSubscriptionPlan::class)($organization, $this->plan('professional'), $admin, '   ');
            $this->fail('A blank reason must be refused.');
        } catch (DomainException $e) {
            $this->assertSame('reason_required', $e->errorCode());
            $this->assertSame('reason', $e->field());
        }

        $this->assertSame($this->plan('starter')->id, $this->subscription($organization)->plan_id);
        $this->assertSame(0, $this->auditCount('subscription.plan_changed'));
    }

    #[Test]
    public function moving_to_the_plan_it_is_already_on_is_refused(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $before = $this->history($organization)->count();

        try {
            app(ChangeSubscriptionPlan::class)($organization, $this->plan('starter'), $admin, 'No-op');
            $this->fail('Moving to the current plan must be refused.');
        } catch (DomainException $e) {
            $this->assertSame('same_plan', $e->errorCode());
        }

        $this->assertSame($before, $this->history($organization)->count());
    }

    #[Test]
    public function moving_to_an_inactive_plan_is_refused(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $target = $this->plan('professional');
        $target->forceFill(['is_active' => false])->save();

        try {
            app(ChangeSubscriptionPlan::class)($organization, $target, $admin, 'Try inactive');
            $this->fail('An inactive plan cannot be sold.');
        } catch (DomainException $e) {
            $this->assertSame('plan_inactive', $e->errorCode());
        }

        $this->assertSame($this->plan('starter')->id, $this->subscription($organization)->plan_id);
    }

    #[Test]
    public function a_plan_change_needs_a_live_subscription(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::Cancelled, $admin, 'Customer left');

        try {
            app(ChangeSubscriptionPlan::class)($organization, $this->plan('professional'), $admin, 'Too late');
            $this->fail('A cancelled subscription cannot change plan.');
        } catch (DomainException $e) {
            $this->assertSame('no_live_subscription', $e->errorCode());
        }
    }

    #[Test]
    public function a_plan_change_holds_a_shared_lock_on_the_plan_it_snapshots(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $professional = $this->plan('professional');

        app(ChangeSubscriptionPlan::class)($organization, $professional, $admin, 'Upgrade');

        // An edit of the plan (FOR UPDATE) cannot slip in between reading the price and snapshotting it.
        $this->assertRowIsLocked('plans', 'id', $professional->id);
    }

    // ── ChangeSubscriptionStatus ────────────────────────────────────────

    /** The specified state machine, written out independently of the code under test. */
    private const ALLOWED = [
        'trialing' => ['active', 'cancelled', 'expired'],
        'active' => ['past_due', 'cancelled'],
        'past_due' => ['active', 'grace', 'cancelled'],
        'grace' => ['active', 'cancelled', 'expired'],
    ];

    private const ALL_STATUSES = ['trialing', 'active', 'past_due', 'grace', 'cancelled', 'expired'];

    /** @return list<array{string, string}> */
    private static function pairs(bool $allowed): array
    {
        $pairs = [];
        foreach (self::ALLOWED as $from => $targets) {
            foreach (self::ALL_STATUSES as $to) {
                if ($to !== $from && in_array($to, $targets, true) === $allowed) {
                    $pairs[] = [$from, $to];
                }
            }
        }

        return $pairs;
    }

    private function organizationWithSubscriptionIn(string $status): Organization
    {
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $this->resetSubscription($organization, $status);

        return $organization;
    }

    /** Put the one subscription back into $status, as test set-up (the domain cannot go backwards). */
    private function resetSubscription(Organization $organization, string $status): void
    {
        $this->subscription($organization)->forceFill([
            'status' => $status, 'cancelled_at' => null, 'ended_at' => null, 'grace_ends_at' => null,
        ])->save();
    }

    #[Test]
    public function every_allowed_transition_is_applied_with_history_and_audit(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $pairs = self::pairs(allowed: true);
        $this->assertCount(11, $pairs, 'The specification has eleven allowed transitions.');

        foreach ($pairs as [$from, $to]) {
            $this->resetSubscription($organization, $from);
            $historyBefore = $this->history($organization)->count();

            app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::from($to), $admin, "Moving {$from} to {$to}");

            $this->assertSame($to, $this->subscription($organization)->status->value, "{$from} to {$to}");

            $history = $this->history($organization);
            $this->assertCount($historyBefore + 1, $history, "{$from} to {$to}: one history row");
            $row = $history->last();
            $this->assertSame('status_changed', $row->event);
            $this->assertSame($from, $row->from_status);
            $this->assertSame($to, $row->to_status);
            $this->assertSame("Moving {$from} to {$to}", $row->reason);
            $this->assertSame($admin->id, $row->actor_user_id);

            $audit = $this->audit('subscription.status_changed');
            $this->assertSame('platform', $audit->context);
            $this->assertSame($organization->id, $audit->organization_id);
            $this->assertSame($from, $audit->before['status']);
            $this->assertSame($to, $audit->after['status']);
            $this->assertSame("Moving {$from} to {$to}", $audit->metadata['reason']);
        }
    }

    #[Test]
    public function every_other_transition_is_refused_and_leaves_nothing_behind(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $pairs = self::pairs(allowed: false);
        // Four live states x five other statuses = 20 moves, eleven of them allowed.
        $this->assertCount(9, $pairs);

        foreach ($pairs as [$from, $to]) {
            $this->resetSubscription($organization, $from);
            $historyBefore = $this->history($organization)->count();
            $auditBefore = $this->auditCount('subscription.status_changed');

            try {
                app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::from($to), $admin, 'Not allowed');
                $this->fail("{$from} to {$to} must be refused.");
            } catch (DomainException $e) {
                $this->assertSame('invalid_transition', $e->errorCode(), "{$from} to {$to}");
            }

            $this->assertSame($from, $this->subscription($organization)->status->value, "{$from} to {$to}: status untouched");
            $this->assertCount($historyBefore, $this->history($organization), "{$from} to {$to}: no history");
            $this->assertSame($auditBefore, $this->auditCount('subscription.status_changed'), "{$from} to {$to}: no audit");
        }
    }

    #[Test]
    public function the_transition_table_matches_the_specification(): void
    {
        foreach (self::pairs(allowed: true) as [$from, $to]) {
            $this->assertTrue(SubscriptionTransitions::canTransition(SubscriptionStatus::from($from), SubscriptionStatus::from($to)), "{$from} to {$to}");
        }
        foreach (self::pairs(allowed: false) as [$from, $to]) {
            $this->assertFalse(SubscriptionTransitions::canTransition(SubscriptionStatus::from($from), SubscriptionStatus::from($to)), "{$from} to {$to}");
        }

        $this->assertTrue(SubscriptionTransitions::isTerminal(SubscriptionStatus::Cancelled));
        $this->assertTrue(SubscriptionTransitions::isTerminal(SubscriptionStatus::Expired));
        $this->assertSame([], SubscriptionTransitions::allowed(SubscriptionStatus::Cancelled));
        $this->assertSame([], SubscriptionTransitions::allowed(SubscriptionStatus::Expired));
        $this->assertFalse(SubscriptionTransitions::isTerminal(SubscriptionStatus::Grace));
    }

    #[Test]
    public function cancelling_stamps_cancelled_and_ended_and_ends_the_organizations_entitlements(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $entitlements = app(EntitlementService::class);
        $this->assertTrue($entitlements->allows($organization, FeatureRegistry::CALENDAR));

        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00'));
        app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::Cancelled, $admin, 'Customer left');

        $subscription = $this->subscription($organization);
        $this->assertTrue($subscription->cancelled_at->equalTo(CarbonImmutable::parse('2026-10-02 12:00:00')));
        $this->assertTrue($subscription->ended_at->equalTo(CarbonImmutable::parse('2026-10-02 12:00:00')));

        // No live subscription: everything is off and every limit is zero.
        $this->assertFalse($entitlements->allows($organization, FeatureRegistry::CALENDAR));
        $this->assertSame(0, $entitlements->limit($organization, FeatureRegistry::MAX_STAFF));
    }

    #[Test]
    public function expiring_stamps_ended_but_not_cancelled(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->organizationWithSubscriptionIn('grace');

        app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::Expired, $admin, 'Grace period lapsed');

        $subscription = $this->subscription($organization);
        $this->assertNull($subscription->cancelled_at);
        $this->assertNotNull($subscription->ended_at);
    }

    #[Test]
    public function entering_grace_stores_the_end_of_grace_in_utc_and_leaving_it_clears_it(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->organizationWithSubscriptionIn('past_due');

        // An instant in another timezone: stored without its offset it would land hours off.
        $endsAt = CarbonImmutable::parse('2026-11-05 23:59:59', 'America/New_York');
        app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::Grace, $admin, 'Payment promised', $endsAt);

        $stored = $this->subscription($organization)->grace_ends_at;
        $this->assertTrue($stored->equalTo($endsAt), 'The grace end must be the same instant that was given.');
        $this->assertSame('2026-11-06 04:59:59', $stored->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($endsAt->utc()->toIso8601String(), $this->history($organization)->last()->metadata['grace_ends_at']);

        app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::Active, $admin, 'Payment received');
        $this->assertNull($this->subscription($organization)->grace_ends_at);
    }

    #[Test]
    public function the_end_of_a_grace_period_must_be_in_the_future(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->organizationWithSubscriptionIn('past_due');

        try {
            app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::Grace, $admin, 'Payment promised', CarbonImmutable::now()->subDay());
            $this->fail('A grace period that has already ended is not a grace period.');
        } catch (DomainException $e) {
            $this->assertSame('grace_in_past', $e->errorCode());
            $this->assertSame('grace_ends_at', $e->field());
        }

        $this->assertSame(SubscriptionStatus::PastDue, $this->subscription($organization)->status);
        $this->assertSame(0, $this->auditCount('subscription.status_changed'));
    }

    #[Test]
    public function converting_a_trial_starts_the_first_paid_period_in_the_snapshotted_interval(): void
    {
        $admin = $this->signInAsPlatform();
        $this->plan('starter')->forceFill(['billing_interval' => 'year'])->save();
        $organization = $this->createOrganization(plan: 'starter', status: OrganizationStatus::Trial)->organization;
        $this->assertSame(SubscriptionStatus::Trialing, $this->subscription($organization)->status);

        $this->travelTo(CarbonImmutable::parse('2026-10-02 09:30:00'));
        app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::Active, $admin, 'Trial converted');

        $subscription = $this->subscription($organization);
        $this->assertTrue($subscription->current_period_starts_at->equalTo(CarbonImmutable::parse('2026-10-02 09:30:00')));
        $this->assertTrue($subscription->current_period_ends_at->equalTo(CarbonImmutable::parse('2027-10-02 09:30:00')));
        $this->assertNotNull($subscription->trial_ends_at, 'The trial end stays on record.');
    }

    #[Test]
    public function asking_for_the_status_it_already_has_changes_nothing(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $historyBefore = $this->history($organization)->count();

        app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::Active, $admin, 'Double click');

        $this->assertCount($historyBefore, $this->history($organization));
        $this->assertSame(0, $this->auditCount('subscription.status_changed'));
    }

    #[Test]
    public function a_status_change_needs_a_reason(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;

        try {
            app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::PastDue, $admin, '');
            $this->fail('A blank reason must be refused.');
        } catch (DomainException $e) {
            $this->assertSame('reason_required', $e->errorCode());
        }

        $this->assertSame(SubscriptionStatus::Active, $this->subscription($organization)->status);
    }

    #[Test]
    public function a_cancelled_subscription_is_final(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::Cancelled, $admin, 'Customer left');

        try {
            app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::Active, $admin, 'Changed their mind');
            $this->fail('A cancelled subscription cannot be revived.');
        } catch (DomainException $e) {
            $this->assertSame('no_live_subscription', $e->errorCode());
        }
    }

    // ── StartOrganizationSubscription ───────────────────────────────────

    #[Test]
    public function a_second_live_subscription_is_refused_with_a_message_not_a_database_error(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;

        try {
            app(StartOrganizationSubscription::class)($organization, $this->plan('professional'), false, $admin, null);
            $this->fail('An organization has at most one live subscription.');
        } catch (DomainException $e) {
            $this->assertSame('subscription_exists', $e->errorCode());
        }

        $this->assertSame(1, Subscription::query()->where('organization_id', $organization->id)->count());
    }

    #[Test]
    public function after_cancelling_a_new_subscription_can_be_started_with_snapshot_history_and_audit(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        $entitlements = app(EntitlementService::class);
        app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::Cancelled, $admin, 'Customer left');
        $this->assertFalse($entitlements->allows($organization, FeatureRegistry::CALENDAR));

        $started = app(StartOrganizationSubscription::class)($organization, $this->plan('professional'), true, $admin, 'Customer came back');

        $this->assertSame(SubscriptionStatus::Trialing, $started->status, 'The plan has trial days and a trial was asked for.');
        $this->assertSame(60000, $started->price_minor);
        $this->assertSame('GHS', $started->currency);
        $this->assertNotNull($started->trial_ends_at);
        $this->assertSame(2, Subscription::query()->where('organization_id', $organization->id)->count(), 'The old subscription stays as history.');

        $row = $this->history($organization)->last();
        $this->assertSame('started', $row->event);
        $this->assertSame($this->plan('professional')->id, $row->to_plan_id);
        $this->assertSame('Customer came back', $row->reason);

        $audit = $this->audit('subscription.started');
        $this->assertSame('platform', $audit->context);
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame('professional', $audit->after['plan']);
        $this->assertSame('trialing', $audit->after['status']);
        $this->assertSame('Customer came back', $audit->metadata['reason']);

        $this->assertTrue($entitlements->allows($organization, FeatureRegistry::CALENDAR), 'Entitlements come back with the new subscription.');
    }

    #[Test]
    public function a_trial_is_only_given_to_a_plan_that_has_trial_days(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::Cancelled, $admin, 'Customer left');

        $started = app(StartOrganizationSubscription::class)($organization, $this->plan('enterprise'), true, $admin, null);

        $this->assertSame(SubscriptionStatus::Active, $started->status, 'Enterprise has no trial days, so it starts active.');
        $this->assertNull($started->trial_ends_at);
    }

    #[Test]
    public function an_inactive_plan_cannot_be_started(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(plan: 'starter')->organization;
        app(ChangeSubscriptionStatus::class)($organization, SubscriptionStatus::Cancelled, $admin, 'Customer left');
        $plan = $this->plan('advanced');
        $plan->forceFill(['is_active' => false])->save();

        try {
            app(StartOrganizationSubscription::class)($organization, $plan, false, $admin, null);
            $this->fail('An inactive plan cannot be sold.');
        } catch (DomainException $e) {
            $this->assertSame('plan_inactive', $e->errorCode());
        }

        $this->assertSame(0, $this->auditCount('subscription.started'));
    }
}
