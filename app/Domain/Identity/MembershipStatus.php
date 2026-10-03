<?php

namespace App\Domain\Identity;

use App\Domain\Shared\LabelledEnum;

enum MembershipStatus: string
{
    use LabelledEnum;

    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';
    case Deactivated = 'deactivated';

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Invited => 'info',
            self::Suspended => 'warning',
            self::Deactivated => 'neutral',
        };
    }
}
