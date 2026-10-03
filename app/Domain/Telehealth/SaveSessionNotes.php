<?php

namespace App\Domain\Telehealth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\SessionNote;
use App\Models\SessionNoteVersion;
use App\Models\TelehealthSession;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Saves the clinician's session summary. An edit is a NEW insert-only version (the earlier text is never
 * overwritten); saving unchanged text adds nothing. The audit entry names the session and the version number
 * only: note text is clinical content and never enters the audit log.
 */
final class SaveSessionNotes
{
    public const MAX_LENGTH = 20000;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /** @return SessionNoteVersion|null the new version, or null when nothing changed */
    public function __invoke(TelehealthSession $session, string $body, ?User $actor = null): ?SessionNoteVersion
    {
        TenantGuard::assertOwned($this->tenant->organizationOrFail()->id, $session);

        $body = trim(str_replace("\r\n", "\n", $body));
        if ($body === '') {
            throw new DomainException('Write something before saving the notes.', 'notes_empty', 'notes');
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new DomainException('Notes may not be longer than '.number_format(self::MAX_LENGTH).' characters.', 'notes_too_long', 'notes');
        }
        if (in_array($session->status, [SessionStatus::Cancelled, SessionStatus::Missed], true)) {
            throw new DomainException('There are no notes to write for a session that did not take place.', 'session_closed', 'notes');
        }

        return DB::transaction(function () use ($session, $body, $actor) {
            $note = $this->lockedNote($session);

            if ($note->latest_version > 0) {
                $current = SessionNoteVersion::query()->where('session_note_id', $note->id)->where('version', $note->latest_version)->first();
                if ($current !== null && $current->body === $body) {
                    return null;
                }
            }

            $version = new SessionNoteVersion;
            $version->forceFill([
                'organization_id' => $note->organization_id,
                'record_environment' => $note->record_environment,
                'session_note_id' => $note->id,
                'version' => $note->latest_version + 1,
                'body' => $body,
                'author_user_id' => $actor?->id,
                'created_at' => now(),
            ])->save();

            $note->forceFill(['latest_version' => $version->version])->save();

            $this->audit->record(
                $version->version === 1 ? 'telehealth.notes_created' : 'telehealth.notes_updated',
                subject: $session,
                metadata: ['version' => $version->version],
                summary: 'Telehealth session notes saved (version '.$version->version.')',
            );

            return $version;
        });
    }

    private function lockedNote(TelehealthSession $session): SessionNote
    {
        $find = fn () => SessionNote::query()->where('telehealth_session_id', $session->id)->lockForUpdate()->first();

        if (($note = $find()) !== null) {
            return $note;
        }

        try {
            DB::transaction(function () use ($session) {
                (new SessionNote)->forceFill([
                    'organization_id' => $session->organization_id,
                    'record_environment' => $session->record_environment,
                    'telehealth_session_id' => $session->id,
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            // Another request created it first; use theirs.
        }

        return $find() ?? throw new DomainException('The notes could not be saved. Try again.', 'notes_unavailable', 'notes');
    }
}
