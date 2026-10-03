<?php

namespace App\Domain\Telehealth\Providers;

use App\Domain\Telehealth\Daily\DailyApi;
use App\Domain\Telehealth\Daily\DailyConfig;
use App\Domain\Telehealth\Daily\DailyException;

/**
 * Daily.co (Daily Prebuilt in an iframe on WellNest's call page). One room per session, created by Daily without
 * a custom name, PRIVATE with knocking and Daily's pre-join screen, so a client who opens the room link waits in
 * the lobby until a staff member with an owner pass admits them. Chat is off (clinical conversation belongs in
 * WellNest's audited messages) and the room allows no recording by itself: only a pass can (see issuePass).
 * Data sent to Daily: docs/compliance/REGISTER.md (third-party processors).
 */
final class DailyProvider implements MeetingProvider
{
    public const KEY = 'daily';

    public function __construct(private readonly DailyConfig $config) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Daily';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(recording: true, transcript: false, waitingRoom: true);
    }

    public function status(): VideoServiceStatus
    {
        return $this->config->status();
    }

    public function createRoom(RoomSpec $spec): VideoRoom
    {
        $room = $this->api()->createRoom($this->roomBody($spec));

        // The URL becomes an iframe source and the client link: only ever a Daily room address.
        $host = strtolower((string) parse_url($room['url'], PHP_URL_HOST));
        if (! str_starts_with($room['url'], 'https://') || ! str_ends_with($host, '.daily.co') || parse_url($room['url'], PHP_URL_PATH) !== '/'.$room['name']) {
            throw DailyException::invalidResponse();
        }

        return new VideoRoom($room['name'], $room['url']);
    }

    public function updateRoom(string $name, RoomSpec $spec): void
    {
        $this->api()->updateRoom($name, $this->roomBody($spec));
    }

    public function deleteRoom(string $name): void
    {
        try {
            $this->api()->deleteRoom($name);
        } catch (VideoServiceException $e) {
            if (! $e->notFound()) {
                throw $e;
            }
        }
    }

    public function issuePass(VideoRoom $room, PassSpec $spec): CallPass
    {
        $properties = [
            'room_name' => $room->name,                     // always: a token without it opens every room of the domain
            'exp' => $spec->expiresAt->getTimestamp(),      // always: a token without it never expires
            'user_name' => mb_substr($spec->userName, 0, 100),
            'user_id' => $spec->userId,                     // a UUID: kept (not replaced by "hipaa") in Daily's HIPAA logs
            'is_owner' => $spec->owner,
            'enable_prejoin_ui' => false,                   // WellNest's own device check already ran
        ];
        if ($spec->startVideoOff) {
            $properties['start_video_off'] = true;
        }
        if ($spec->startAudioOff) {
            $properties['start_audio_off'] = true;
        }
        if ($spec->record) {
            $properties['enable_recording'] = 'cloud';
            $properties['enable_recording_ui'] = true;
        }

        $token = $this->api()->createMeetingToken($properties);

        return new CallPass($room->url.'?t='.rawurlencode($token), $spec->expiresAt);
    }

    public function stopRecording(string $roomName): void
    {
        $this->api()->stopRecording($roomName);
    }

    public function recordingDownloadUrl(string $recordingId, int $validForSeconds): string
    {
        return $this->api()->recordingAccessLink($recordingId, $validForSeconds)['url'];
    }

    public function deleteRecording(string $recordingId): void
    {
        try {
            $this->api()->deleteRecording($recordingId);
        } catch (VideoServiceException $e) {
            if (! $e->notFound()) {
                throw $e;
            }
        }
    }

    /** @return array{privacy: string, properties: array<string, mixed>} */
    private function roomBody(RoomSpec $spec): array
    {
        $properties = [
            'nbf' => $spec->notBefore->getTimestamp(),
            'exp' => $spec->expiresAt->getTimestamp(),
            'eject_at_room_exp' => true,
            'enable_knocking' => true,
            'enable_prejoin_ui' => true,    // Prebuilt's lobby needs it for a knocking client
            'enable_chat' => false,
        ];
        if (($geo = $this->config->geo()) !== null) {
            $properties['geo'] = $geo;
        }

        return ['privacy' => 'private', 'properties' => $properties];
    }

    private function api(): DailyApi
    {
        return $this->config->client();
    }
}
