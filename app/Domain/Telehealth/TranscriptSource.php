<?php

namespace App\Domain\Telehealth;

enum TranscriptSource: string
{
    case Provider = 'provider';
    case Ai = 'ai';
}
