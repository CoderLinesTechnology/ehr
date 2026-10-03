<?php

namespace App\Domain\Messaging;

use Carbon\CarbonImmutable;

/**
 * @param  list<array{emoji: string, count: int, mine: bool}>  $reactions
 * @param  list<array{id: string, name: string, size: int, mime: string}>  $attachments
 */
final readonly class ThreadMessage
{
    public function __construct(
        public string $id,
        public bool $mine,
        public string $sender,
        public string $body,
        public CarbonImmutable $at,
        public bool $retracted,
        public array $reactions,
        public array $attachments,
        public bool $canRetract,
        public bool $read,
        public string $cursor,
    ) {}

    public function isImage(array $attachment): bool
    {
        return str_starts_with($attachment['mime'], 'image/');
    }
}
