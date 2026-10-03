<?php

namespace App\Domain\Telehealth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Shared\DomainException;
use App\Domain\Telehealth\Providers\ProviderRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Models\SessionRecording;
use App\Models\TelehealthSession;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores a consented recording on the private disk. Refused unless the organization allows recording, the
 * client's consent is recorded for this session and the session's provider supports it (the database refuses a
 * recording row for a session without consent as well). The type is decided by sniffing the bytes; the stored
 * name is generated, never the uploaded one.
 */
final class AttachRecording
{
    public const DISK = 'local';

    public const MAX_BYTES = 300 * 1024 * 1024;

    /** detected mime => extension */
    private const TYPES = [
        'audio/x-wav' => 'wav', 'audio/wav' => 'wav', 'audio/vnd.wave' => 'wav',
        'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/ogg' => 'ogg', 'audio/webm' => 'webm',
        'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly TelehealthSettings $settings,
        private readonly ProviderRegistry $providers,
    ) {}

    public function __invoke(TelehealthSession $session, UploadedFile $file, ?int $durationSeconds = null, ?User $actor = null): SessionRecording
    {
        $organization = $this->tenant->organizationOrFail();
        TenantGuard::assertOwned($organization->id, $session);

        if (! $this->settings->recordingEnabled($organization)) {
            throw new DomainException('Recording is not enabled for your organization.', 'recording_disabled', 'recording');
        }
        if (! $this->providers->get($session->provider_key)->capabilities()->recording) {
            throw new DomainException('This session\'s video service does not support recordings.', 'recording_unsupported', 'recording');
        }
        if (! $session->consent_to_record) {
            throw new DomainException('Record the client\'s consent before attaching a recording.', 'consent_required', 'recording');
        }

        $path = $file->getRealPath();
        if (! $file->isValid() || $path === false) {
            throw new DomainException('That file could not be uploaded. Try again.', 'recording_invalid', 'recording');
        }
        $size = (int) filesize($path);
        if ($size === 0 || $size > self::MAX_BYTES) {
            throw new DomainException('A recording must be between 1 byte and '.(self::MAX_BYTES / 1048576).' MB.', 'recording_size', 'recording');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        if (! isset(self::TYPES[$mime])) {
            throw new DomainException('Only audio or video recordings (WAV, MP3, M4A, MP4, WebM, MOV, OGG) can be attached.', 'recording_type', 'recording');
        }

        $sha256 = hash_file('sha256', $path); // before the transaction: hashing up to 300 MB must not hold the row lock
        $stored = "telehealth/{$organization->id}/{$session->id}/".Str::uuid7()->toString().'.'.self::TYPES[$mime];
        $stream = fopen($path, 'rb');
        Storage::disk(self::DISK)->put($stored, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        try {
            return DB::transaction(function () use ($session, $stored, $mime, $size, $sha256, $durationSeconds, $actor) {
                $locked = TelehealthSession::query()->lockForUpdate()->findOrFail($session->id);
                if (! $locked->consent_to_record) {
                    throw new DomainException('Record the client\'s consent before attaching a recording.', 'consent_required', 'recording');
                }

                $recording = new SessionRecording;
                $recording->forceFill([
                    'organization_id' => $locked->organization_id,
                    'record_environment' => $locked->record_environment,
                    'telehealth_session_id' => $locked->id,
                    'consented' => true,
                    'storage_path' => $stored,
                    'mime' => $mime,
                    'size_bytes' => $size,
                    'duration_seconds' => $durationSeconds,
                    'sha256' => $sha256,
                    'created_by_user_id' => $actor?->id,
                ])->save();

                $this->audit->record(
                    'telehealth.recording_attached',
                    subject: $recording,
                    metadata: ['size_bytes' => $size, 'mime' => $mime],
                    summary: 'A consented telehealth recording was attached',
                );

                return $recording;
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($stored);

            throw $e;
        }
    }
}
