<?php

namespace App\Domain\Saas;

use App\Domain\Shared\LabelledEnum;

enum SubscriptionStatus: string
{
    use LabelledEnum;

    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Grace = 'grace';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** Matches the partial unique index: at most one live subscription per organization. */
    public const LIVE = ['trialing', 'active', 'past_due', 'grace'];

    public function isLive(): bool
    {
        return in_array($this->value, self::LIVE, true);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Trialing => 'info',
            self::PastDue, self::Grace => 'warning',
            self::Cancelled, self::Expired => 'neutral',
        };
    }
}
