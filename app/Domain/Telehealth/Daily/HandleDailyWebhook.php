<?php

namespace App\Domain\Telehealth\Daily;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\RecordEnvironment;
use App\Domain\Telehealth\Providers\DailyProvider;
use App\Domain\Telehealth\Providers\ProviderRegistry;
use App\Domain\Telehealth\Providers\VideoServiceException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\SessionRecording;
use App\Models\TelehealthSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a VERIFIED Daily webhook event (the controller checked the signature on the raw body first). Daily's
 * webhooks are domain-wide, so an event for a room WellNest does not know is acknowledged and ignored, as is the
 * signed {"test":"test"} ping and every event type not handled here.
 *
 *  - recording.ready-to-download: the session is found by its room name, then everything runs INSIDE that
 *    session's organization. With the client's consent recorded the recording is stored as metadata (Daily keeps
 *    the file; downloads use a short-lived link minted per download) — idempotent on Daily's recording id. Without
 *    consent it is deleted at Daily and nothing is stored; a failed delete answers 503 so Daily retries.
 *  - recording.error: audited and logged (the action only, never Daily's message), nothing else.
 *
 * Audit entries name the session and the outcome, never Daily's ids.
 */
final class HandleDailyWebhook
{
    public const RECORDING_READY = 'recording.ready-to-download';

    public const RECORDING_ERROR = 'recording.error';

    /** The event types WellNest subscribes to (telehealth:daily-webhook). */
    public const EVENTS = [self::RECORDING_READY, self::RECORDING_ERROR];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProviderRegistry $providers,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<mixed> $event the decoded body of a delivery whose signature was verified */
    public function __invoke(array $event): WebhookOutcome
    {
        $type = $event['type'] ?? null;
        $payload = $event['payload'] ?? null;
        if (! is_string($type) || ! is_array($payload)) {
            return WebhookOutcome::Ignored;   // the {"test":"test"} verification ping, or a shape we do not know
        }

        return match ($type) {
            self::RECORDING_READY => $this->recordingReady($payload),
            self::RECORDING_ERROR => $this->recordingError($payload),
            default => WebhookOutcome::Ignored,
        };
    }

    /** @param array<mixed> $payload */
    private function recordingReady(array $payload): WebhookOutcome
    {
        $recordingId = self::identifier($payload['recording_id'] ?? null, 64);
        $room = self::identifier($payload['room_name'] ?? null, 128);
        $found = $room === null || $recordingId === null ? null : $this->locate($room);
        if ($found === null) {
            return WebhookOutcome::Ignored;
        }

        $duration = is_numeric($payload['duration'] ?? null) ? max(0, min(86400, (int) round((float) $payload['duration']))) : null;

        return $this->tenant->runAs($found[0], fn () => $this->attach($found[1], $recordingId, $duration));
    }

    private function attach(string $sessionId, string $recordingId, ?int $duration): WebhookOutcome
    {
        if (SessionRecording::query()->where('provider_recording_id', $recordingId)->exists()) {
            return WebhookOutcome::Duplicate;
        }

        try {
            $stored = DB::transaction(function () use ($sessionId, $recordingId, $duration) {
                // Locked: consent cannot be withdrawn between this check and the insert (RecordConsent locks the same row).
                $session = TelehealthSession::query()->lockForUpdate()->findOrFail($sessionId);
                if (! $session->consent_to_record) {
                    return false;
                }

                $recording = new SessionRecording;
                $recording->forceFill([
                    'organization_id' => $session->organization_id,
                    'record_environment' => $session->record_environment,
                    'telehealth_session_id' => $session->id,
                    'consented' => true,
                    'provider_key' => $session->provider_key,
                    'provider_recording_id' => $recordingId,
                    'mime' => 'video/mp4',
                    'duration_seconds' => $duration,
                ])->save();

                $this->audit->record(
                    'telehealth.recording_attached',
                    subject: $recording,
                    metadata: ['source' => 'video_service', 'duration_seconds' => $duration],
                    summary: 'A consented telehealth recording arrived from the video service',
                );

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return WebhookOutcome::Duplicate;   // a concurrent delivery of the same recording won
        }

        return $stored ? WebhookOutcome::Processed : $this->refuse($sessionId, $recordingId);
    }

    /** No consent for this session: the recording must not be kept anywhere. */
    private function refuse(string $sessionId, string $recordingId): WebhookOutcome
    {
        $session = TelehealthSession::query()->findOrFail($sessionId);
        $deleted = true;
        try {
            $this->providers->get($session->provider_key)->deleteRecording($recordingId);
        } catch (VideoServiceException $e) {
            $deleted = false;
            Log::warning('Telehealth: an unconsented recording could not be deleted at the video service yet', ['error' => $e->errorType]);
        }

        $this->audit->record(
            'telehealth.recording_refused',
            subject: $session,
            metadata: ['reason' => 'no_consent', 'deleted_at_video_service' => $deleted],
            summary: 'A recording made without recorded client consent was refused'.($deleted ? ' and deleted at the video service' : '; deleting it at the video service will be retried'),
        );

        return $deleted ? WebhookOutcome::Processed : WebhookOutcome::RetryLater;
    }

    /** @param array<mixed> $payload */
    private function recordingError(array $payload): WebhookOutcome
    {
        $room = self::identifier($payload['room_name'] ?? null, 128);
        $action = is_string($payload['action'] ?? null) ? mb_substr(preg_replace('/[^a-z0-9._-]/i', '', $payload['action']) ?? '', 0, 64) : '';
        $found = $room === null ? null : $this->locate($room);
        if ($found === null) {
            return WebhookOutcome::Ignored;
        }

        Log::warning('Telehealth: the video service reported a recording error', ['action' => $action]);

        return $this->tenant->runAs($found[0], function () use ($found, $action) {
            $this->audit->record(
                'telehealth.recording_failed',
                subject: TelehealthSession::query()->findOrFail($found[1]),
                metadata: array_filter(['action' => $action]),
                summary: 'The video service reported that a session recording failed',
            );

            return WebhookOutcome::Processed;
        });
    }

    /**
     * The organization and session that own a Daily room, or null for a room WellNest does not know (or a demo
     * session's, which never has one).
     *
     * @return array{0: Organization, 1: string}|null
     */
    private function locate(string $room): ?array
    {
        // Deliberate cross-tenant lookup: Daily's webhooks are domain-wide and name only the room, so the session is
        // found across organizations through the unique room-name index. It reads three columns — id, organization
        // and environment — to learn which tenant to enter; nothing clinical is read outside that tenant.
        $row = $this->tenant->bypass(fn () => TelehealthSession::query()
            ->where('provider_key', DailyProvider::KEY)
            ->where('provider_room_name', $room)
            ->first(['id', 'organization_id', 'record_environment']));
        if ($row === null || $row->record_environment === RecordEnvironment::Demo) {
            return null;
        }

        $organization = Organization::query()->find($row->organization_id);

        return $organization === null ? null : [$organization, $row->id];
    }

    private static function identifier(mixed $value, int $max): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,'.$max.'}$/', $value) === 1 ? $value : null;
    }
}
