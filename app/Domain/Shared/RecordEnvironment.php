<?php

namespace App\Domain\Shared;

/** Demo records are real rows that must never be mistaken for, or counted as, live data. */
enum RecordEnvironment: string
{
    use LabelledEnum;

    case Live = 'live';
    case Demo = 'demo';
}
