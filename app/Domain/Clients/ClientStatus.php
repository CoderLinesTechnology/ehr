<?php

namespace App\Domain\Clients;

use App\Domain\Shared\LabelledEnum;

enum ClientStatus: string
{
    use LabelledEnum;

    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'warning',
            self::Archived => 'neutral',
        };
    }
}
