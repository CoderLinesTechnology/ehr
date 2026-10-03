<?php

namespace App\Domain\Scheduling;

use App\Domain\Shared\LabelledEnum;

enum Modality: string
{
    use LabelledEnum;

    case InPerson = 'in_person';
    case Telehealth = 'telehealth';

    public function label(): string
    {
        return match ($this) {
            self::InPerson => 'In person',
            self::Telehealth => 'Telehealth',
        };
    }
}
