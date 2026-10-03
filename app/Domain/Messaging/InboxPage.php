<?php

namespace App\Domain\Messaging;

/** @param list<InboxRow> $rows */
final readonly class InboxPage
{
    /**
     * @param  list<InboxRow>  $rows
     * @param  array{all: int, clients: int, team: int, groups: int}  $unread  conversations with unread messages per tab
     */
    public function __construct(public array $rows, public ?string $next, public array $unread, public string $tab, public string $q, public bool $unreadOnly = false) {}
}
