<?php

namespace App\Domain\Scheduling;

use App\Domain\Shared\LabelledEnum;

enum AvailabilityModality: string
{
    use LabelledEnum;

    case InPerson = 'in_person';
    case Telehealth = 'telehealth';
    case Any = 'any';

    public function permits(Modality $modality): bool
    {
        return $this === self::Any || $this->value === $modality->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::InPerson => 'In person',
            self::Telehealth => 'Telehealth',
            self::Any => 'In person or telehealth',
        };
    }
}
