<?php

namespace App\Domain\Clients;

use App\Domain\Shared\LabelledEnum;

enum ClientStatus: string
{
    use LabelledEnum;

    /** Registered, intake not yet complete (not counted as an active client). */
    case Pending = 'pending';
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Pending => 'info',
            self::Inactive => 'warning',
            self::Archived => 'neutral',
        };
    }
}
