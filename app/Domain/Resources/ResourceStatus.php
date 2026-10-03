<?php

namespace App\Domain\Resources;

enum ResourceStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'pending',
            self::Published => 'success',
            self::Archived => 'neutral',
        };
    }
}
