<?php

namespace App\Domain\Programs;

use App\Domain\Shared\LabelledEnum;

/**
 * Program lifecycle. ONE table of allowed moves, read by the action that enforces it and by the screen that
 * offers the buttons. Open programs (upcoming, active, on hold) use a place of the plan's max_programs limit.
 */
enum ProgramStatus: string
{
    use LabelledEnum;

    case Upcoming = 'upcoming';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Archived = 'archived';

    public const OPEN = ['upcoming', 'active', 'on_hold'];

    public function label(): string
    {
        return $this === self::OnHold ? 'On Hold' : ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Upcoming => 'warning',
            self::OnHold => 'neutral',
            self::Completed => 'info',
            self::Archived => 'neutral',
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
            self::Upcoming => [self::Active, self::Archived],
            self::Active => [self::OnHold, self::Completed],
            self::OnHold => [self::Active, self::Completed, self::Archived],
            self::Completed => [self::Archived],
            self::Archived => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** The verb on the button that moves a program to $this. */
    public function action(): string
    {
        return match ($this) {
            self::Active => 'Activate',
            self::OnHold => 'Put on hold',
            self::Completed => 'Mark completed',
            self::Archived => 'Archive',
            self::Upcoming => 'Set as upcoming',
        };
    }
}
