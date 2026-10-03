<?php

namespace App\Domain\Messaging;

use App\Domain\Shared\DomainException;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;

/** Moves the member's read marker forward to the newest message (never backwards). Not audited: it is not a change to records. */
final class MarkRead
{
    public function __invoke(OrganizationMembership $member, Conversation $conversation): void
    {
        if (! ConversationAccess::allows($conversation, $member)) {
            throw new DomainException('You can no longer read this conversation.', 'messaging_forbidden');
        }

        DB::transaction(function () use ($member, $conversation) {
            $participant = ConversationParticipant::query()->where('conversation_id', $conversation->id)
                ->where('membership_id', $member->id)->whereNull('left_at')->lockForUpdate()->first();
            if ($participant === null) {
                return;
            }

            $latest = Message::query()->where('conversation_id', $conversation->id)
                ->where('created_at', '>=', $participant->joined_at->format('Y-m-d H:i:s.uP'))
                ->orderByDesc('created_at')->orderByDesc('id')->first(['id', 'created_at']);

            if ($latest !== null && ($participant->last_read_at === null || $participant->last_read_at->lessThan($latest->created_at))) {
                $participant->forceFill(['last_read_at' => $latest->created_at, 'last_read_message_id' => $latest->id])->save();
            }
        });
    }
}
