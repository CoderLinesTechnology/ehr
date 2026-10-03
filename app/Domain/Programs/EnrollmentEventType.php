<?php

namespace App\Domain\Programs;

use App\Domain\Shared\LabelledEnum;

enum EnrollmentEventType: string
{
    use LabelledEnum;

    case Admitted = 'admitted';
    case LevelChanged = 'level_changed';
    case PutOnHold = 'put_on_hold';
    case Resumed = 'resumed';
    case Discharged = 'discharged';
    case Completed = 'completed';
    case Transferred = 'transferred';

    public function label(): string
    {
        return match ($this) {
            self::LevelChanged => 'Level of care changed',
            self::PutOnHold => 'Put on hold',
            default => ucfirst($this->value),
        };
    }
}
