<?php

namespace App\Domain\Clients;

use App\Domain\Shared\LabelledEnum;

/** Matches the clients_sex_check constraint. */
enum ClientSex: string
{
    use LabelledEnum;

    case Female = 'female';
    case Male = 'male';
    case Intersex = 'intersex';
    case Unknown = 'unknown';
    case Undisclosed = 'undisclosed';

    public function label(): string
    {
        return match ($this) {
            self::Undisclosed => 'Prefer not to say',
            default => ucfirst($this->value),
        };
    }
}
