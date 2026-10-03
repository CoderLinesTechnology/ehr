<?php

namespace App\Domain\Messaging;

use App\Models\Conversation;

final readonly class ThreadPage
{
    /**
     * @param  list<ThreadMessage>  $messages  oldest first
     * @param  list<string>  $members  names of the current participants (groups only)
     * @param  list<string>  $memberIds  their membership keys, for the "add people" form only (never displayed)
     */
    public function __construct(
        public Conversation $conversation,
        public string $title,
        public ?bool $online,
        public int $memberCount,
        public array $members,
        public array $memberIds,
        public array $messages,
        public ?string $earlier,
        public ?string $readUntil,
        public ?string $latest,
    ) {}
}
