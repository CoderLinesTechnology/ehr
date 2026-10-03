<?php

namespace App\Domain\Messaging;

use App\Models\Conversation;
use App\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\Builder;

/** Conversations with something the member has not read: the nav badge and the tab counts. */
final class UnreadCount
{
    /** SQL for "how many unread messages does this participant row have" (needs aliases conversations and p). */
    public static function messagesSql(): string
    {
        return '(select count(*) from messages um where um.conversation_id = conversations.id and um.organization_id = conversations.organization_id'
            .' and um.sender_membership_id <> p.membership_id and um.retracted_at is null'
            .' and um.created_at > coalesce(p.last_read_at, p.joined_at))';
    }

    /** The member's visible conversations (participant join included), un-filtered. @return Builder<Conversation> */
    public static function visible(OrganizationMembership $membership): Builder
    {
        $query = Conversation::query()
            ->join('conversation_participants as p', function ($join) use ($membership) {
                $join->on('p.conversation_id', '=', 'conversations.id')
                    ->where('p.membership_id', $membership->id)
                    ->where('p.organization_id', $membership->organization_id)
                    ->whereNull('p.left_at');
            });

        return ConversationAccess::restrict($query, $membership);
    }

    /** Number of conversations with unread messages. */
    public static function for(OrganizationMembership $membership): int
    {
        return (int) self::visible($membership)->whereRaw(self::messagesSql().' > 0')->count();
    }

    /**
     * Unread conversations per kind, plus a signature of the inbox that changes whenever a message arrives
     * (the page's poll compares it to decide whether to refresh the list).
     *
     * @return array{total: int, byKind: array<string, int>, signature: string}
     */
    public static function summary(OrganizationMembership $membership): array
    {
        $byKind = self::visible($membership)->whereRaw(self::messagesSql().' > 0')
            ->groupBy('conversations.kind')->selectRaw('conversations.kind as kind, count(*) as n')
            ->pluck('n', 'kind')->map(fn ($n) => (int) $n)->all();
        $latest = self::visible($membership)->max('conversations.last_message_at');

        return [
            'total' => array_sum($byKind),
            'byKind' => $byKind,
            'signature' => md5(($latest ?? '').'|'.json_encode($byKind)),
        ];
    }
}
