<?php

namespace App\Domain\Scheduling;

use App\Domain\Shared\LabelledEnum;

enum BlockedTimeKind: string
{
    use LabelledEnum;

    case Blocked = 'blocked';
    case Leave = 'leave';
    case Holiday = 'holiday';
    case Unavailable = 'unavailable';
}
