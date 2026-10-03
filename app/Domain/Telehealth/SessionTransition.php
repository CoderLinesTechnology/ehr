<?php

namespace App\Domain\Telehealth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\TelehealthSession;
use App\Models\TelehealthSessionStatusHistory;

/**
 * The one place a session's status changes: checks the state machine, stamps the columns the caller passes,
 * writes the insert-only history row and the audit entry (status only — never the room, link or any clinical
 * text). A session that finishes (completed, cancelled, missed) gives its video room back after commit.
 * Callers hold the row lock; this does not lock.
 */
final class SessionTransition
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ReleaseRoom $rooms,
    ) {}

    /** @param array<string, mixed> $changes extra columns written together with the status */
    public function apply(TelehealthSession $locked, SessionStatus $to, ?string $actorUserId, ?string $reason = null, array $changes = []): void
    {
        $from = $locked->status;
        if (! $from->canTransitionTo($to)) {
            throw new DomainException("A session that is “{$from->label()}” cannot be changed to “{$to->label()}”.", 'invalid_session_transition', 'status');
        }

        $locked->forceFill($changes + ['status' => $to])->save();

        (new TelehealthSessionStatusHistory)->forceFill([
            'organization_id' => $locked->organization_id,
            'record_environment' => $locked->record_environment,
            'telehealth_session_id' => $locked->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'actor_user_id' => $actorUserId,
            'occurred_at' => now(),
        ])->save();

        $this->audit->record(
            'telehealth.session_status_changed',
            subject: $locked,
            before: ['status' => $from->value],
            after: ['status' => $to->value],
            metadata: array_filter(['reason' => $reason]),
            summary: "Telehealth session {$from->label()} → {$to->label()}",
        );

        if (! $to->isOpen()) {
            $this->rooms->afterCommit($locked);
        }
    }
}
