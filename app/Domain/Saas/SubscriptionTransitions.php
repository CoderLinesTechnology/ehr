<?php

namespace App\Domain\Saas;

/**
 * The subscription state machine: which status may follow which. Cancelled and
 * expired are final (a new subscription is started instead). One definition,
 * used by the action that enforces it and by the screen that offers it.
 */
final class SubscriptionTransitions
{
    /** @return list<SubscriptionStatus> */
    public static function allowed(SubscriptionStatus $from): array
    {
        return match ($from) {
            SubscriptionStatus::Trialing => [SubscriptionStatus::Active, SubscriptionStatus::Cancelled, SubscriptionStatus::Expired],
            SubscriptionStatus::Active => [SubscriptionStatus::PastDue, SubscriptionStatus::Cancelled],
            SubscriptionStatus::PastDue => [SubscriptionStatus::Active, SubscriptionStatus::Grace, SubscriptionStatus::Cancelled],
            SubscriptionStatus::Grace => [SubscriptionStatus::Active, SubscriptionStatus::Cancelled, SubscriptionStatus::Expired],
            SubscriptionStatus::Cancelled, SubscriptionStatus::Expired => [],
        };
    }

    public static function canTransition(SubscriptionStatus $from, SubscriptionStatus $to): bool
    {
        return in_array($to, self::allowed($from), true);
    }

    public static function isTerminal(SubscriptionStatus $status): bool
    {
        return self::allowed($status) === [];
    }

    /** Button label for moving a subscription into $to. */
    public static function actionLabel(SubscriptionStatus $to): string
    {
        return match ($to) {
            SubscriptionStatus::Trialing => 'Start trial',
            SubscriptionStatus::Active => 'Mark active',
            SubscriptionStatus::PastDue => 'Mark past due',
            SubscriptionStatus::Grace => 'Start grace period',
            SubscriptionStatus::Cancelled => 'Cancel subscription',
            SubscriptionStatus::Expired => 'Mark expired',
        };
    }

    /** Moves that end or endanger access: shown as destructive actions. */
    public static function isDestructive(SubscriptionStatus $to): bool
    {
        return in_array($to, [SubscriptionStatus::Cancelled, SubscriptionStatus::Expired], true);
    }
}
