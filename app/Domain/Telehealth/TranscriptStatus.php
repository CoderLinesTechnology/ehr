<?php

namespace App\Domain\Telehealth;

enum TranscriptStatus: string
{
    case Draft = 'draft';
    case Reviewed = 'reviewed';
}
