<?php

namespace App\Domain\Programs;

use App\Domain\Shared\LabelledEnum;

enum AttendanceStatus: string
{
    use LabelledEnum;

    case Present = 'present';
    case Absent = 'absent';
    case Excused = 'excused';

    public function tone(): string
    {
        return match ($this) {
            self::Present => 'success',
            self::Absent => 'danger',
            self::Excused => 'neutral',
        };
    }
}
