<?php

namespace App\Domain\Scheduling\Support;

use App\Domain\Settings\SettingsService;
use App\Models\Organization;

/**
 * Typed reads of the organization's scheduling.* settings. Every value is read
 * at call time, so an administrator's change applies to the next booking.
 */
final class SchedulingSettings
{
    public function __construct(private readonly SettingsService $settings) {}

    public function slotIntervalMinutes(Organization|string $organization): int
    {
        $minutes = (int) $this->settings->organization($organization, 'scheduling.slot_interval_minutes');

        return $minutes > 0 ? $minutes : 15;
    }

    public function minNoticeHours(Organization|string $organization): int
    {
        return max(0, (int) $this->settings->organization($organization, 'scheduling.min_notice_hours'));
    }

    public function maxAdvanceDays(Organization|string $organization): int
    {
        return max(1, (int) $this->settings->organization($organization, 'scheduling.max_advance_days'));
    }

    public function cancellationNoticeHours(Organization|string $organization): int
    {
        return max(0, (int) $this->settings->organization($organization, 'scheduling.cancellation_notice_hours'));
    }

    public function allowOverbooking(Organization|string $organization): bool
    {
        return (bool) $this->settings->organization($organization, 'scheduling.allow_overbooking');
    }
}
