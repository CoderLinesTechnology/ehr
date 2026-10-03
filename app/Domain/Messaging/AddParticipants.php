<?php

namespace App\Domain\Messaging;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Adds staff to a group or client thread. New people see messages from the moment they join, not the history. */
final class AddParticipants
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param list<string> $membershipIds @return int how many people were added */
    public function __invoke(OrganizationMembership $actor, Conversation $conversation, array $membershipIds): int
    {
        if ($conversation->kind === ConversationKind::Direct) {
            throw new DomainException('A one-to-one conversation cannot have more people.', 'direct_fixed');
        }
        if (! ConversationAccess::allows($conversation, $actor)) {
            throw new DomainException('You can no longer change this conversation.', 'messaging_forbidden');
        }

        $ids = array_values(array_unique(array_filter($membershipIds, fn ($id) => is_string($id) && Str::isUuid($id))));
        $members = $ids === [] ? collect() : OrganizationMembership::query()->whereIn('id', $ids)->get();
        $client = $conversation->kind === ConversationKind::Client ? Client::query()->find($conversation->client_id) : null;

        if ($members->isEmpty() || $members->count() !== count($ids)
            || $members->contains(fn (OrganizationMembership $m) => ! ConversationAccess::mayJoin($m, $conversation->kind, $client))) {
            throw new DomainException('One of the people you chose cannot be added to this conversation.', 'participant_not_allowed', 'participants');
        }

        return DB::transaction(function () use ($conversation, $members, $actor) {
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();   // serialises the size check
            $rows = ConversationParticipant::query()->where('conversation_id', $conversation->id)->get()->keyBy('membership_id');
            $active = $rows->whereNull('left_at')->count();
            $added = 0;
            $now = now()->utc();

            foreach ($members as $member) {
                $row = $rows->get($member->id);
                if ($row !== null && $row->left_at === null) {
                    continue;
                }
                if (++$active > StartConversation::MAX_GROUP) {
                    throw new DomainException('A conversation can have at most '.StartConversation::MAX_GROUP.' people.', 'group_size', 'participants');
                }
                $row ??= new ConversationParticipant;
                $row->forceFill([
                    'conversation_id' => $conversation->id, 'membership_id' => $member->id,
                    'joined_at' => $now, 'left_at' => null, 'last_read_at' => null, 'last_read_message_id' => null,
                ])->save();
                $added++;
            }

            if ($added > 0) {
                $this->audit->record('conversation.participants_added', $conversation, metadata: ['added' => $added, 'added_by' => $actor->displayName()], summary: 'People added to a conversation');
            }

            return $added;
        });
    }
}
