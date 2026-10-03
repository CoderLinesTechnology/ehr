<?php

namespace App\Domain\Programs;

use App\Domain\Shared\LabelledEnum;

/** The colour family of a program's icon tile (comp 03) and the icons offered for it. */
enum ProgramColor: string
{
    use LabelledEnum;

    case Blue = 'blue';
    case Green = 'green';
    case Purple = 'purple';
    case Orange = 'orange';
    case Red = 'red';
    case Teal = 'teal';

    /** Lucide icons a program may use (a name outside this list is refused). */
    public const ICONS = [
        'users-round', 'users', 'sprout', 'leaf', 'heart-pulse', 'sun', 'flower-2', 'brain', 'house-heart',
        'graduation-cap', 'handshake', 'activity', 'heart', 'smile', 'shield', 'puzzle', 'baby',
    ];

    public function defaultIcon(): string
    {
        return match ($this) {
            self::Blue => 'users',
            self::Green => 'sprout',
            self::Purple => 'heart-pulse',
            self::Orange => 'sun',
            self::Red => 'users-round',
            self::Teal => 'flower-2',
        };
    }
}
