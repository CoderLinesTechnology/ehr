<?php

namespace App\Domain\Telehealth;

use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\Modality;
use App\Models\Appointment;
use App\Models\TelehealthSession;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the session in step with its appointment. Called by the scheduling listener after commit (booked,
 * status changed, rescheduled); idempotent, so a replayed event changes nothing.
 */
final class SyncTelehealthSession
{
    public function __construct(
        private readonly EnsureTelehealthSession $ensure,
        private readonly SessionTransition $transition,
    ) {}

    public function __invoke(Appointment $appointment, ?string $reason = null, ?string $actorUserId = null, ?TelehealthSession $copyLinkFrom = null): ?TelehealthSession
    {
        if ($appointment->modality !== Modality::Telehealth) {
            // Moved to in-person: an open session has nothing left to do.
            $session = TelehealthSession::query()->where('appointment_id', $appointment->id)->first();
            if ($session !== null && $session->status->isOpen()) {
                DB::transaction(fn () => $this->moveTo($session->id, SessionStatus::Cancelled, 'Appointment is no longer telehealth', $actorUserId, $appointment));
            }

            return $session?->refresh();
        }

        $session = ($this->ensure)($appointment, $copyLinkFrom?->join_url, $actorUserId);
        if ($session === null) {
            return null;
        }

        $target = self::targetFor($appointment->status);
        if ($target !== $session->status) {
            DB::transaction(fn () => $this->moveTo($session->id, $target, $reason, $actorUserId, $appointment));
        }

        return $session->refresh();
    }

    /** The session status an appointment status implies. */
    public static function targetFor(AppointmentStatus $status): SessionStatus
    {
        return match ($status) {
            AppointmentStatus::Scheduled, AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn => SessionStatus::Scheduled,
            AppointmentStatus::InProgress => SessionStatus::InProgress,
            AppointmentStatus::Completed => SessionStatus::Completed,
            AppointmentStatus::NoShow => SessionStatus::Missed,
            AppointmentStatus::Cancelled, AppointmentStatus::Rescheduled => SessionStatus::Cancelled,
        };
    }

    private function moveTo(string $sessionId, SessionStatus $target, ?string $reason, ?string $actorUserId, Appointment $appointment): void
    {
        /** @var TelehealthSession $locked */
        $locked = TelehealthSession::query()->lockForUpdate()->findOrFail($sessionId);

        // A late event (the session already finished, or moved on) never drags it backwards.
        if ($locked->status === $target || ! $locked->status->canTransitionTo($target)) {
            return;
        }

        $changes = [];
        if ($target === SessionStatus::InProgress) {
            $changes['started_at'] = $appointment->started_at ?? now();
        }
        if ($target === SessionStatus::Completed) {
            $endedAt = $appointment->completed_at ?? now();
            $startedAt = $locked->started_at;
            $changes += ['ended_at' => $endedAt, 'duration_minutes' => $startedAt !== null
                ? (int) max(0, $startedAt->diffInMinutes($endedAt))
                : $appointment->durationMinutes()];
        }

        $this->transition->apply($locked, $target, $actorUserId, $reason, $changes);
    }
}
