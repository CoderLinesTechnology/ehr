<?php

namespace App\Domain\Telehealth;

use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\Modality;
use App\Models\Appointment;
use App\Models\TelehealthSession;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the session in step with its appointment. Called by the scheduling listener after commit (booked,
 * status changed, rescheduled); idempotent, so a replayed event changes nothing. A reschedule MOVES the video
 * room to the replacement session ($moveRoomFrom), so the link the client already has keeps working; the new
 * window is sent to the vendor the next time the room is prepared.
 */
final class SyncTelehealthSession
{
    public function __construct(
        private readonly EnsureTelehealthSession $ensure,
        private readonly SessionTransition $transition,
    ) {}

    public function __invoke(Appointment $appointment, ?string $reason = null, ?string $actorUserId = null, ?TelehealthSession $moveRoomFrom = null): ?TelehealthSession
    {
        if ($appointment->modality !== Modality::Telehealth) {
            // Moved to in-person: an open session has nothing left to do.
            $session = TelehealthSession::query()->where('appointment_id', $appointment->id)->first();
            if ($session !== null && $session->status->isOpen()) {
                DB::transaction(fn () => $this->moveTo($session->id, SessionStatus::Cancelled, 'Appointment is no longer telehealth', $actorUserId, $appointment));
            }

            return $session?->refresh();
        }

        $session = ($this->ensure)($appointment, $actorUserId);
        if ($session === null) {
            return null;
        }
        if ($moveRoomFrom !== null) {
            $this->moveRoom($moveRoomFrom->id, $session->id);
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

    /**
     * The replacement takes the old session's room and the old one lets go of it, in one transaction (old cleared
     * first: the room name is unique). Both rows are locked in id order. Nothing happens when the old session has no
     * room, the new one already has one, or the new one is no longer open.
     */
    private function moveRoom(string $fromId, string $toId): void
    {
        if ($fromId === $toId) {
            return;
        }

        DB::transaction(function () use ($fromId, $toId) {
            $rows = TelehealthSession::query()->whereKey([$fromId, $toId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $rows->get($fromId);
            $to = $rows->get($toId);
            if ($from === null || $to === null || $from->provider_room_name === null || $to->provider_room_name !== null
                || ! $to->status->isOpen() || $from->provider_key !== $to->provider_key) {
                return;
            }

            $room = [
                'provider_room_name' => $from->provider_room_name,
                'join_url' => $from->join_url,
                'provider_room_nbf' => $from->provider_room_nbf,
                'provider_room_exp' => $from->provider_room_exp,
            ];
            $from->forceFill(['provider_room_name' => null, 'join_url' => null, 'provider_room_nbf' => null, 'provider_room_exp' => null])->save();
            $to->forceFill($room)->save();
        });
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
