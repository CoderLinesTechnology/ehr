<?php

namespace App\Domain\Programs;

use App\Domain\Shared\LabelledEnum;

/**
 * One participant's place in one program. The only table of allowed moves: TransitionEnrollment enforces it and the
 * participant screen offers its buttons from it. Closed statuses never reopen (a returning client is a new enrollment).
 */
enum EnrollmentStatus: string
{
    use LabelledEnum;

    case Pending = 'pending';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Discharged = 'discharged';
    case Transferred = 'transferred';

    /** The statuses of an open enrollment: the database allows one per client and program. */
    public const OPEN = ['pending', 'active', 'on_hold'];

    public function label(): string
    {
        return $this === self::OnHold ? 'On hold' : ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Pending => 'info',
            self::OnHold => 'warning',
            self::Completed => 'completed',
            self::Discharged, self::Transferred => 'neutral',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this->value, self::OPEN, true);
    }

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Discharged],
            self::Active => [self::OnHold, self::Completed, self::Discharged, self::Transferred],
            self::OnHold => [self::Active, self::Completed, self::Discharged, self::Transferred],
            self::Completed, self::Discharged, self::Transferred => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }
}
