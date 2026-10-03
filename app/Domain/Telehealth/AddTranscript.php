<?php

namespace App\Domain\Telehealth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\SessionRecording;
use App\Models\SessionTranscript;
use App\Models\TelehealthSession;
use App\Models\User;

/**
 * Adds a transcript to a consented session. It is ALWAYS a draft: nothing here (or anywhere) finalises it, a
 * clinician does that with ReviewTranscript. An AI transcript additionally needs the organization's opt-in.
 * (The video vendor's own transcript is source "provider"; a future AI job calls this with source "ai".)
 */
final class AddTranscript
{
    public const MAX_LENGTH = 500000;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly TelehealthSettings $settings,
    ) {}

    public function __invoke(TelehealthSession $session, string $body, TranscriptSource $source, ?SessionRecording $recording = null, ?User $actor = null): SessionTranscript
    {
        $organization = $this->tenant->organizationOrFail();
        TenantGuard::assertOwned($organization->id, $session, $recording);

        if (! $session->consent_to_record) {
            throw new DomainException('Record the client\'s consent before adding a transcript.', 'consent_required', 'transcript');
        }
        if ($source === TranscriptSource::Ai && ! $this->settings->aiTranscriptsEnabled($organization)) {
            throw new DomainException('AI transcripts are not enabled for your organization.', 'ai_disabled', 'transcript');
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::MAX_LENGTH) {
            throw new DomainException('A transcript must have text and be at most '.number_format(self::MAX_LENGTH).' characters.', 'transcript_invalid', 'transcript');
        }
        if ($recording !== null && $recording->telehealth_session_id !== $session->id) {
            throw new DomainException('That recording belongs to another session.', 'recording_mismatch', 'transcript');
        }

        $transcript = new SessionTranscript;
        $transcript->forceFill([
            'organization_id' => $session->organization_id,
            'record_environment' => $session->record_environment,
            'telehealth_session_id' => $session->id,
            'consented' => true,
            'session_recording_id' => $recording?->id,
            'source' => $source,
            'status' => TranscriptStatus::Draft,
            'body' => $body,
        ])->save();

        $this->audit->record(
            'telehealth.transcript_added',
            subject: $transcript,
            after: ['source' => $source->value, 'status' => 'draft'],
            summary: ($source === TranscriptSource::Ai ? 'An AI' : 'A provider').' transcript was added as a draft',
        );

        return $transcript;
    }
}
