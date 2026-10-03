<?php

namespace App\Domain\Telehealth;

use App\Domain\Shared\LabelledEnum;

/**
 * The telehealth session state machine. Every change goes through a Telehealth action, which records the
 * insert-only history. "Waiting" is reserved for a client waiting in the provider's lobby: Daily has one, but
 * WellNest does not observe it yet (the call page loads no vendor script and the lobby webhooks are not
 * subscribed), so sessions go scheduled → in progress when a staff member starts the call.
 */
enum SessionStatus: string
{
    use LabelledEnum;

    case Scheduled = 'scheduled';
    case Waiting = 'waiting';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Missed = 'missed';

    /** Not finished: still to happen or happening. */
    public const OPEN = ['scheduled', 'waiting', 'in_progress'];

    public const TERMINAL = ['completed', 'cancelled', 'missed'];

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::Waiting, self::InProgress, self::Completed, self::Cancelled, self::Missed],
            self::Waiting => [self::InProgress, self::Completed, self::Cancelled, self::Missed],
            self::InProgress => [self::Completed],
            self::Completed, self::Cancelled, self::Missed => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isOpen(): bool
    {
        return in_array($this->value, self::OPEN, true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Upcoming',
            self::Waiting => 'Waiting',
            self::InProgress => 'In progress',
            default => ucfirst($this->value),
        };
    }

    /** Pill tone: upcoming blue, in progress amber, completed green, cancelled grey, missed red. */
    public function tone(): string
    {
        return match ($this) {
            self::Scheduled, self::Waiting => 'info',
            self::InProgress => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'neutral',
            self::Missed => 'danger',
        };
    }
}
