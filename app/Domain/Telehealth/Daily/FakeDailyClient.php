<?php

namespace App\Domain\Telehealth\Daily;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;

/**
 * Daily without the network, for local development (DAILY_FAKE=true) and tests. Used only when DailyConfig::fake()
 * says so — never in production. Rooms look like Daily's (`https://wellnest-dev.daily.co/<20-char name>`), tokens
 * and recordings are inert placeholders, and every call is recorded so tests can assert what would have been sent.
 */
#[Singleton]
final class FakeDailyClient implements DailyApi
{
    public const DOMAIN = 'https://wellnest-dev.daily.co';

    /** @var list<array{0: string, 1: array<string, mixed>}> operation, arguments */
    public array $calls = [];

    public function createRoom(array $body): array
    {
        $name = Str::lower(Str::random(20));
        $this->calls[] = ['rooms.create', ['body' => $body, 'name' => $name]];

        return ['name' => $name, 'url' => self::DOMAIN.'/'.$name];
    }

    public function updateRoom(string $name, array $body): void
    {
        $this->calls[] = ['rooms.update', ['name' => $name, 'body' => $body]];
    }

    public function deleteRoom(string $name): void
    {
        $this->calls[] = ['rooms.delete', ['name' => $name]];
    }

    public function createMeetingToken(array $properties): string
    {
        if (! is_string($properties['room_name'] ?? null) || ! is_int($properties['exp'] ?? null)) {
            throw DailyException::invalidRequest();
        }
        $this->calls[] = ['meeting-tokens.create', ['properties' => $properties]];

        return 'fake-token-'.Str::random(32);
    }

    public function getRecording(string $id): array
    {
        $this->calls[] = ['recordings.get', ['id' => $id]];

        return ['id' => $id, 'status' => 'finished', 'duration' => 0];
    }

    public function recordingAccessLink(string $id, int $validForSeconds): array
    {
        $this->calls[] = ['recordings.access-link', ['id' => $id, 'valid_for_secs' => $validForSeconds]];
        $expires = now()->addSeconds($validForSeconds)->getTimestamp();

        return ['url' => self::DOMAIN.'/recordings/'.rawurlencode($id).'.mp4?expires='.$expires, 'expires' => $expires];
    }

    public function deleteRecording(string $id): void
    {
        $this->calls[] = ['recordings.delete', ['id' => $id]];
    }

    public function stopRecording(string $roomName): void
    {
        $this->calls[] = ['recordings.stop', ['room' => $roomName]];
    }

    public function upsertWebhook(string $url, string $hmac, array $eventTypes, ?string $uuid = null): array
    {
        $this->calls[] = ['webhooks.upsert', ['url' => $url, 'eventTypes' => $eventTypes, 'uuid' => $uuid]];

        return ['uuid' => $uuid ?? 'fake-webhook', 'state' => 'ACTIVE'];
    }

    /** @return list<array<string, mixed>> the arguments of every call of one operation */
    public function callsOf(string $operation): array
    {
        return array_values(array_map(fn (array $call) => $call[1], array_filter($this->calls, fn (array $call) => $call[0] === $operation)));
    }
}
