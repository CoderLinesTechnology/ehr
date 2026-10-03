<?php

namespace App\Domain\Telehealth\Daily;

/**
 * The Daily REST API as WellNest uses it (field names: docs.daily.co, verified 2026-10-03). Implemented by
 * DailyClient (the only class that talks HTTP) and FakeDailyClient (local development and tests, no network).
 * Every method throws DailyException on failure.
 */
interface DailyApi
{
    /**
     * POST /rooms. The body never names the room: Daily generates the name (HIPAA mode refuses custom names).
     *
     * @param  array{privacy: string, properties: array<string, mixed>}  $body
     * @return array{name: string, url: string}
     */
    public function createRoom(array $body): array;

    /**
     * POST /rooms/:name.
     *
     * @param  array{privacy?: string, properties?: array<string, mixed>}  $body
     */
    public function updateRoom(string $name, array $body): void;

    /** DELETE /rooms/:name (ejects everyone still in it). */
    public function deleteRoom(string $name): void;

    /**
     * POST /meeting-tokens. The properties always carry `room_name` and `exp` (a token without a room name is
     * valid for every room of the domain, one without `exp` forever).
     *
     * @param  array<string, mixed>  $properties
     */
    public function createMeetingToken(array $properties): string;

    /** @return array<string, mixed> GET /recordings/:id */
    public function getRecording(string $id): array;

    /** @return array{url: string, expires: int} GET /recordings/:id/access-link?valid_for_secs=N (900 … 43200) */
    public function recordingAccessLink(string $id, int $validForSeconds): array;

    /** DELETE /recordings/:id */
    public function deleteRecording(string $id): void;

    /** POST /rooms/:name/recordings/stop */
    public function stopRecording(string $roomName): void;

    /**
     * Creates (POST /webhooks) or updates (POST /webhooks/:uuid) the domain's webhook. Daily pings the URL with a
     * signed {"test":"test"} before answering, so the receiver must already be deployed.
     *
     * @param  list<string>  $eventTypes
     * @return array{uuid: string, state: string}
     */
    public function upsertWebhook(string $url, string $hmac, array $eventTypes, ?string $uuid = null): array;
}
