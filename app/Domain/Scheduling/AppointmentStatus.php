<?php

namespace App\Domain\Scheduling;

use App\Domain\Shared\LabelledEnum;

/**
 * The appointment state machine. Every transition goes through
 * TransitionAppointment, which records history; dashboards and the
 * overlap constraint reuse OCCUPYING so the definitions cannot drift.
 */
enum AppointmentStatus: string
{
    use LabelledEnum;

    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Rescheduled = 'rescheduled';

    /** Statuses that hold the clinician's time (mirrors appointments_no_clinician_overlap). */
    public const OCCUPYING = ['scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed'];

    /** Not yet happened and still expected to. */
    public const UPCOMING = ['scheduled', 'confirmed'];

    public const TERMINAL = ['completed', 'cancelled', 'no_show', 'rescheduled'];

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::Confirmed, self::CheckedIn, self::InProgress, self::Completed, self::Cancelled, self::NoShow],
            self::Confirmed => [self::CheckedIn, self::InProgress, self::Completed, self::Cancelled, self::NoShow],
            self::CheckedIn => [self::InProgress, self::Completed, self::Cancelled],
            self::InProgress => [self::Completed],
            self::Completed, self::Cancelled, self::NoShow, self::Rescheduled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->value, self::TERMINAL, true);
    }

    public function isOccupying(): bool
    {
        return in_array($this->value, self::OCCUPYING, true);
    }

    public function canReschedule(): bool
    {
        return in_array($this->value, self::UPCOMING, true);
    }

    /**
     * How long before the start this status may be entered, in minutes; null
     * means any time. Check-in and start may happen a little early; completion
     * and no-show only once the appointment has started.
     */
    public function earliestMinutesBeforeStart(): ?int
    {
        return match ($this) {
            self::CheckedIn, self::InProgress => 120,
            self::Completed, self::NoShow => 0,
            default => null,
        };
    }

    /** Whether the clock allows entering this status for an appointment starting at $startsAt. */
    public function canEnterAt(\DateTimeInterface $startsAt, \DateTimeInterface $now): bool
    {
        $minutes = $this->earliestMinutesBeforeStart();

        return $minutes === null || $now->getTimestamp() >= $startsAt->getTimestamp() - $minutes * 60;
    }

    /** Milestone column stamped when entering this status. */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Confirmed => 'confirmed_at',
            self::CheckedIn => 'checked_in_at',
            self::InProgress => 'started_at',
            self::Completed => 'completed_at',
            self::Cancelled => 'cancelled_at',
            self::NoShow => 'no_show_at',
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CheckedIn => 'Checked in',
            self::InProgress => 'In progress',
            self::NoShow => 'No-show',
            default => ucfirst($this->value),
        };
    }

    public function actionLabel(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirm',
            self::CheckedIn => 'Check in',
            self::InProgress => 'Start',
            self::Completed => 'Complete',
            self::Cancelled => 'Cancel',
            self::NoShow => 'Mark no-show',
            default => $this->label(),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Scheduled => 'info',
            self::Confirmed => 'primary',
            self::CheckedIn, self::InProgress => 'warning',
            self::Completed => 'success',
            self::Cancelled, self::Rescheduled => 'neutral',
            self::NoShow => 'danger',
        };
    }
}
