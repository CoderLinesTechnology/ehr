<?php

namespace App\Domain\Telehealth\Providers;

use App\Models\TelehealthSession;
use App\Models\User;

/**
 * A video vendor behind the telehealth module (architecture §12). Core code depends on this interface only;
 * which provider a session uses is stored on the session (`provider_key`), so a vendor can be added without
 * touching the sessions that already exist.
 */
interface MeetingProvider
{
    /** Stable key stored on the session (`external_link`). */
    public function key(): string;

    /** Name shown to staff ("External meeting link"). */
    public function label(): string;

    public function capabilities(): ProviderCapabilities;

    /**
     * Prepare the meeting for a session. May call the vendor (an API provider would) — the external-link
     * provider makes no outbound call: it validates and records the link it was given.
     */
    public function createMeeting(MeetingRequest $request): MeetingDetails;

    /** The URL this viewer opens to join, or null when there is none (or it is no longer acceptable). */
    public function joinUrlFor(TelehealthSession $session, User $viewer): ?string;
}
