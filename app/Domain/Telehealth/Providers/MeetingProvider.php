<?php

namespace App\Domain\Telehealth\Providers;

/**
 * A video vendor behind the telehealth module (architecture §12). Core code depends on this interface only;
 * which provider a session uses is stored on the session (`provider_key`).
 *
 * An API provider hands out ROOMS (one per session, created by the vendor, private, with a lobby) and PASSES
 * (short-lived, room-bound credentials for one staff member). Clients get no pass: they open the room's own
 * link and wait in the lobby until someone with an owner pass admits them. Every method that talks to the
 * vendor throws VideoServiceException on failure; callers turn that into a safe message, never a 500.
 */
interface MeetingProvider
{
    /** Stable key stored on the session (`daily`). */
    public function key(): string;

    /** Vendor name shown to staff ("Daily"). */
    public function label(): string;

    public function capabilities(): ProviderCapabilities;

    /** Whether the vendor is set up on this installation (no outbound call is made when it is not). */
    public function status(): VideoServiceStatus;

    /** @throws VideoServiceException */
    public function createRoom(RoomSpec $spec): VideoRoom;

    /** @throws VideoServiceException (notFound when the room no longer exists) */
    public function updateRoom(string $name, RoomSpec $spec): void;

    /** Deleting a room that is already gone is not an error. Ejects everyone still in it. @throws VideoServiceException */
    public function deleteRoom(string $name): void;

    /** A pass for one staff member; the returned frame URL carries the credential and must never be stored or logged. @throws VideoServiceException */
    public function issuePass(VideoRoom $room, PassSpec $spec): CallPass;

    /** Stops a cloud recording that is running in the room (consent withdrawn). @throws VideoServiceException */
    public function stopRecording(string $roomName): void;

    /** A short-lived download link for a vendor-stored recording; minted per download, never stored. @throws VideoServiceException */
    public function recordingDownloadUrl(string $recordingId, int $validForSeconds): string;

    /** @throws VideoServiceException */
    public function deleteRecording(string $recordingId): void;
}
