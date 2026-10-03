<?php

namespace App\Domain\Telehealth;

use App\Domain\Settings\SettingsService;
use App\Models\Organization;

/** Typed reads of the organization's telehealth.* settings, always at call time. */
final class TelehealthSettings
{
    /** TransitionAppointment refuses to start an appointment more than 2 hours early, so the join window cannot be wider. */
    public const MAX_EARLY_MINUTES = 120;

    public function __construct(private readonly SettingsService $settings) {}

    public function joinEarlyMinutes(Organization|string $organization): int
    {
        return max(0, (int) $this->settings->organization($organization, 'telehealth.join_early_minutes'));
    }

    public function recordingEnabled(Organization|string $organization): bool
    {
        return (bool) $this->settings->organization($organization, 'telehealth.recording_enabled');
    }

    public function aiTranscriptsEnabled(Organization|string $organization): bool
    {
        return (bool) $this->settings->organization($organization, 'telehealth.ai_transcripts_enabled');
    }
}
