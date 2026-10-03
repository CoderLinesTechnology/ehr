<?php

namespace App\Domain\Telehealth\Daily;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only class that talks to Daily over HTTP: Bearer auth, a 3 s connect / 8 s total budget (a request thread
 * waits on it), JSON in and out. Errors become a DailyException carrying Daily's stable `error` type and the HTTP
 * status; the log line names the operation and that type only — never the key, a token, the body or Daily's
 * free-text `info`. Names and ids are checked before they become part of a URL path.
 */
final class DailyClient implements DailyApi
{
    private const NAME = '/^[A-Za-z0-9_-]{1,128}$/';

    public function __construct(private readonly DailyConfig $config) {}

    public function createRoom(array $body): array
    {
        $data = $this->send('rooms.create', 'post', 'rooms', $body);
        $name = $data['name'] ?? null;
        $url = $data['url'] ?? null;
        if (! is_string($name) || preg_match(self::NAME, $name) !== 1 || ! is_string($url)) {
            throw DailyException::invalidResponse();
        }

        return ['name' => $name, 'url' => $url];
    }

    public function updateRoom(string $name, array $body): void
    {
        $this->send('rooms.update', 'post', 'rooms/'.$this->segment($name), $body);
    }

    public function deleteRoom(string $name): void
    {
        $this->send('rooms.delete', 'delete', 'rooms/'.$this->segment($name));
    }

    public function createMeetingToken(array $properties): string
    {
        if (! is_string($properties['room_name'] ?? null) || ! is_int($properties['exp'] ?? null)) {
            throw DailyException::invalidRequest(); // never mint a token valid for every room, or forever
        }

        $token = $this->send('meeting-tokens.create', 'post', 'meeting-tokens', ['properties' => $properties])['token'] ?? null;
        if (! is_string($token) || $token === '' || strlen($token) > 4096) {
            throw DailyException::invalidResponse();
        }

        return $token;
    }

    public function getRecording(string $id): array
    {
        return $this->send('recordings.get', 'get', 'recordings/'.$this->segment($id));
    }

    public function recordingAccessLink(string $id, int $validForSeconds): array
    {
        $data = $this->send('recordings.access-link', 'get', 'recordings/'.$this->segment($id).'/access-link', [
            'valid_for_secs' => max(900, min(43200, $validForSeconds)),
        ]);
        $url = $data['download_link'] ?? null;
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw DailyException::invalidResponse();
        }

        return ['url' => $url, 'expires' => (int) ($data['expires'] ?? 0)];
    }

    public function deleteRecording(string $id): void
    {
        $this->send('recordings.delete', 'delete', 'recordings/'.$this->segment($id));
    }

    public function stopRecording(string $roomName): void
    {
        $this->send('recordings.stop', 'post', 'rooms/'.$this->segment($roomName).'/recordings/stop', []);
    }

    public function upsertWebhook(string $url, string $hmac, array $eventTypes, ?string $uuid = null): array
    {
        $body = ['url' => $url, 'hmac' => $hmac, 'eventTypes' => array_values($eventTypes), 'retryType' => 'exponential'];
        $data = $uuid === null
            ? $this->send('webhooks.create', 'post', 'webhooks', $body)
            : $this->send('webhooks.update', 'post', 'webhooks/'.$this->segment($uuid), $body);

        return ['uuid' => (string) ($data['uuid'] ?? $uuid ?? ''), 'state' => (string) ($data['state'] ?? '')];
    }

    /**
     * @param  'get'|'post'|'delete'  $method
     * @param  array<string, mixed>|null  $payload  JSON body (post) or query (get)
     * @return array<string, mixed>
     */
    private function send(string $operation, string $method, string $path, ?array $payload = null): array
    {
        try {
            $request = $this->request();
            /** @var Response $response */
            $response = match ($method) {
                'get' => $request->get($path, $payload ?? []),
                'post' => $request->post($path, $payload ?? []),
                'delete' => $request->delete($path),
            };
        } catch (ConnectionException) {
            Log::warning('Daily API unreachable', ['operation' => $operation]);

            throw DailyException::unreachable();
        }

        if ($response->successful()) {
            $data = $response->json();

            return is_array($data) ? $data : [];
        }

        $type = $response->json('error');
        $type = is_string($type) && preg_match('/^[a-z0-9-]{1,64}$/', $type) === 1 ? $type : 'http-error';
        Log::warning('Daily API error', ['operation' => $operation, 'error' => $type, 'status' => $response->status()]);

        throw new DailyException($type, $response->status());
    }

    private function request(): PendingRequest
    {
        $key = $this->config->apiKey() ?? throw DailyException::notConfigured();

        return Http::baseUrl($this->config->apiBase())
            ->withToken($key)
            ->acceptJson()
            ->asJson()
            ->withOptions(['allow_redirects' => false])   // the API never redirects: never carry the key to another host
            ->connectTimeout($this->config->connectTimeout())
            ->timeout($this->config->timeout());
    }

    private function segment(string $value): string
    {
        if (preg_match(self::NAME, $value) !== 1) {
            throw DailyException::invalidRequest();
        }

        return rawurlencode($value);
    }
}
