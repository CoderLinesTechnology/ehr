<?php

namespace App\Domain\Telehealth\Daily;

/** What became of a verified webhook delivery. Only RetryLater asks Daily to deliver it again. */
enum WebhookOutcome: string
{
    case Processed = 'processed';
    case Duplicate = 'duplicate';
    case Ignored = 'ignored';
    case RetryLater = 'retry_later';

    public function httpStatus(): int
    {
        return $this === self::RetryLater ? 503 : 200;
    }
}
