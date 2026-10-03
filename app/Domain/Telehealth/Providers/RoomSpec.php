<?php

namespace App\Domain\Telehealth\Providers;

use Carbon\CarbonImmutable;

/** When a session's room may be entered: from $notBefore (join window opens) until $expiresAt. */
final readonly class RoomSpec
{
    public function __construct(
        public CarbonImmutable $notBefore,
        public CarbonImmutable $expiresAt,
    ) {}
}
