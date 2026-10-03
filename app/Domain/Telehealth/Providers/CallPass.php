<?php

namespace App\Domain\Telehealth\Providers;

use Carbon\CarbonImmutable;

/**
 * The URL the call page frames. It carries the staff member's credential: it is rendered into a `no-store` page
 * and nowhere else — never stored, logged or audited.
 */
final readonly class CallPass
{
    public function __construct(
        public string $frameUrl,
        public CarbonImmutable $expiresAt,
    ) {}
}
