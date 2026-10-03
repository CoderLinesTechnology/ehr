<?php

namespace App\Domain\Messaging;

use Carbon\CarbonImmutable;

/** "Online" = seen within the last five minutes. Shown only to people who share a conversation with the user. */
final class Presence
{
    public const ONLINE_WITHIN_MINUTES = 5;

    public static function isOnline(?string $lastSeenAt): bool
    {
        return $lastSeenAt !== null
            && CarbonImmutable::parse($lastSeenAt)->greaterThan(now()->subMinutes(self::ONLINE_WITHIN_MINUTES));
    }
}
