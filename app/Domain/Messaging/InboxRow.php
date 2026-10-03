<?php

namespace App\Domain\Messaging;

use Carbon\CarbonImmutable;

/** One line of the conversation list. Holds display data only (no internal ids beyond the conversation key used in URLs). */
final readonly class InboxRow
{
    public function __construct(
        public string $id,
        public ConversationKind $kind,
        public string $name,
        public int $unread,
        public string $preview,
        public ?CarbonImmutable $at,
        public bool $online,
        public string $cursor,
    ) {}
}
