<?php

namespace App\Domain\Messaging;

use App\Domain\Shared\DomainException;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;

/**
 * One reaction per participant per message, from a small allowlist. The same emoji again removes it;
 * a different one replaces it. Returns the reaction now in place, or null when it was removed.
 */
final class React
{
    public function __invoke(OrganizationMembership $member, Message $message, string $emoji): ?MessageReaction
    {
        if (! Reactions::allows($emoji)) {
            throw new DomainException('That reaction is not available.', 'reaction_not_allowed', 'emoji');
        }

        return DB::transaction(function () use ($member, $message, $emoji) {
            // Serialise reactors on one message so two taps cannot race the unique index.
            $locked = Message::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();
            $conversation = $locked->conversation()->firstOrFail();

            if (! ConversationAccess::allows($conversation, $member) || $locked->isRetracted()) {
                throw new DomainException('You cannot react to this message.', 'reaction_forbidden');
            }

            $current = MessageReaction::query()->where('message_id', $locked->id)->where('membership_id', $member->id)->first();

            if ($current?->emoji === $emoji) {
                $current->delete();

                return null;
            }
            if ($current !== null) {
                $current->forceFill(['emoji' => $emoji])->save();

                return $current;
            }

            $reaction = new MessageReaction;
            $reaction->forceFill(['conversation_id' => $conversation->id, 'message_id' => $locked->id, 'membership_id' => $member->id, 'emoji' => $emoji])->save();

            return $reaction;
        });
    }
}
