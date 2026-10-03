<?php

namespace App\Domain\Platform;

/**
 * Entry point the dashboard page calls. The numbers themselves are PlatformMetrics'.
 */
final class PlatformDashboard
{
    public const PERIODS = PlatformMetrics::PERIODS;

    public const DEFAULT_PERIOD = PlatformMetrics::DEFAULT_PERIOD;

    public function __construct(private readonly PlatformMetrics $metrics) {}

    /** @return array<string, mixed> see PlatformMetrics::summary() */
    public function __invoke(int $periodDays = self::DEFAULT_PERIOD): array
    {
        return $this->metrics->summary($periodDays);
    }
}
