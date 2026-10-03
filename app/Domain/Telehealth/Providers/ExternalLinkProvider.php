<?php

namespace App\Domain\Telehealth\Providers;

use App\Domain\Settings\SettingsService;
use App\Models\Organization;
use App\Models\TelehealthSession;
use App\Models\User;

/**
 * "External meeting link": the clinician (or the organization's default) supplies an https Zoom, Google Meet
 * or Microsoft Teams link. WellNest makes no outbound call and loads no vendor script; it stores the link and
 * sends the staff member's own browser there. The customer needs its own BAA/DPA with the video vendor.
 */
final class ExternalLinkProvider implements MeetingProvider
{
    public const KEY = 'external_link';

    public function __construct(
        private readonly MeetingLinkPolicy $links,
        private readonly SettingsService $settings,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'External meeting link';
    }

    public function capabilities(): ProviderCapabilities
    {
        // The vendor records and transcribes on its side; WellNest keeps a consented copy that staff attach.
        return new ProviderCapabilities(recording: true, transcript: true, waitingRoom: false);
    }

    public function createMeeting(MeetingRequest $request): MeetingDetails
    {
        $url = $request->suppliedUrl;
        if ($url === null || trim($url) === '') {
            $default = $this->settings->organization($request->organization, 'telehealth.default_link_secret');
            $url = is_string($default) && $default !== '' ? $default : null;
        }

        // A stored default that no longer passes the allowlist is simply not used.
        if ($url !== null && $request->suppliedUrl === null && ! $this->links->accepts($url, $request->organization)) {
            return new MeetingDetails(null);
        }

        return new MeetingDetails($url === null ? null : $this->links->normalize($url, $request->organization));
    }

    public function joinUrlFor(TelehealthSession $session, User $viewer): ?string
    {
        $url = $session->join_url;
        if (! is_string($url) || $url === '') {
            return null;
        }

        $organization = Organization::query()->find($session->organization_id);

        return $organization !== null && $this->links->accepts($url, $organization) ? $url : null;
    }
}
