<?php

namespace App\Domain\Scheduling;

use App\Domain\Shared\LabelledEnum;

/** Who cancelled. Only client cancellations can be late (and later carry a fee). */
enum CancellationKind: string
{
    use LabelledEnum;

    case Client = 'client';
    case Practice = 'practice';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Cancelled by the client',
            self::Practice => 'Cancelled by the practice',
        };
    }
}
