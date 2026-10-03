<?php

namespace App\Domain\Messaging;

use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageReaction;
use App\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One conversation for one participant: the latest 50 messages ("load earlier" pages backwards by
 * keyset on (created_at, id)), or only what arrived after a cursor (the poll). Never shows messages
 * from before the member joined. A constant number of queries however long the thread.
 */
final class ThreadReader
{
    public const PAGE = 50;

    public function page(Conversation $conversation, OrganizationMembership $member, ?string $before = null): ThreadPage
    {
        $participant = ConversationAccess::participant($conversation, $member);
        $query = $this->base($conversation, $participant);

        if ($cursor = self::parse($before)) {
            $query->whereRaw('(messages.created_at, messages.id) < (?::timestamptz, ?::uuid)', $cursor);
        }

        $rows = $query->orderByDesc('messages.created_at')->orderByDesc('messages.id')->limit(self::PAGE + 1)->get();
        $hasEarlier = $rows->count() > self::PAGE;
        $messages = $this->present($conversation, $member, $rows->take(self::PAGE)->reverse()->values()->all());

        return $this->assemble($conversation, $member, $messages, $hasEarlier);
    }

    /** Messages newer than $after (the cursor of the last one the browser has), oldest first. @return list<ThreadMessage> */
    public function after(Conversation $conversation, OrganizationMembership $member, string $after): array
    {
        $cursor = self::parse($after);
        $participant = ConversationAccess::participant($conversation, $member);
        if ($cursor === null || $participant === null) {
            return [];
        }

        $rows = $this->base($conversation, $participant)
            ->whereRaw('(messages.created_at, messages.id) > (?::timestamptz, ?::uuid)', $cursor)
            ->orderBy('messages.created_at')->orderBy('messages.id')->limit(100)->get();

        return $this->present($conversation, $member, $rows->all());
    }

    /** One message as the member sees it (after a reaction or retraction). */
    public function one(Conversation $conversation, OrganizationMembership $member, Message $message): ?ThreadMessage
    {
        $participant = ConversationAccess::participant($conversation, $member);
        $rows = $this->base($conversation, $participant)->where('messages.id', $message->id)->get()->all();

        return $this->present($conversation, $member, $rows)[0] ?? null;
    }

    /** When the others last read (messages up to this instant have a double check), or null. */
    public function readUntil(Conversation $conversation, OrganizationMembership $member): ?string
    {
        $at = ConversationParticipant::query()->where('conversation_id', $conversation->id)
            ->where('membership_id', '<>', $member->id)->whereNull('left_at')->max('last_read_at');

        return $at === null ? null : CarbonImmutable::parse($at)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function base(Conversation $conversation, ?ConversationParticipant $participant)
    {
        return Message::query()
            ->join('organization_memberships as sm', 'sm.id', '=', 'messages.sender_membership_id')
            ->join('users as su', 'su.id', '=', 'sm.user_id')
            ->where('messages.conversation_id', $conversation->id)
            ->where('messages.created_at', '>=', ($participant?->joined_at ?? now())->format('Y-m-d H:i:s.uP'))
            ->select(['messages.*', 'su.name as sender_name']);
    }

    /** @param list<Message> $rows @return list<ThreadMessage> */
    private function present(Conversation $conversation, OrganizationMembership $member, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(fn (Message $m) => $m->id, $rows);
        $reactions = MessageReaction::query()->whereIn('message_id', $ids)->get(['message_id', 'membership_id', 'emoji'])->groupBy('message_id');
        $files = MessageAttachment::query()->whereIn('message_id', $ids)->whereNull('purged_at')->get(['id', 'message_id', 'original_name', 'size_bytes', 'mime'])->groupBy('message_id');
        $readUntil = $this->readUntil($conversation, $member);
        $readAt = $readUntil === null ? null : CarbonImmutable::parse($readUntil);

        return array_map(function (Message $m) use ($member, $reactions, $files, $readAt) {
            $mine = $m->sender_membership_id === $member->id;

            return new ThreadMessage(
                id: $m->id,
                mine: $mine,
                sender: (string) $m->sender_name,
                body: $m->body,
                at: $m->created_at,
                retracted: $m->isRetracted(),
                reactions: collect($reactions->get($m->id, []))->groupBy('emoji')->map(fn ($group, $emoji) => [
                    'emoji' => (string) $emoji, 'count' => $group->count(), 'mine' => $group->contains('membership_id', $member->id),
                ])->values()->all(),
                attachments: collect($files->get($m->id, []))->map(fn ($f) => [
                    'id' => $f->id, 'name' => $f->original_name, 'size' => $f->size_bytes, 'mime' => $f->mime,
                ])->all(),
                canRetract: $mine && ! $m->isRetracted() && $m->created_at->addMinutes(RetractMessage::WINDOW_MINUTES)->isFuture(),
                read: $mine && $readAt !== null && $m->created_at->lessThanOrEqualTo($readAt),
                cursor: self::cursor($m),
            );
        }, $rows);
    }

    /** @param list<ThreadMessage> $messages */
    private function assemble(Conversation $conversation, OrganizationMembership $member, array $messages, bool $hasEarlier): ThreadPage
    {
        $online = null;
        $members = [];
        $memberIds = [];
        $count = 0;

        if ($conversation->kind === ConversationKind::Client) {
            $client = Client::query()->find($conversation->client_id, ['id', 'first_name', 'last_name']);
            $title = $client?->fullName() ?? 'Client';
        } else {
            $people = ConversationParticipant::query()
                ->join('organization_memberships as om', 'om.id', '=', 'conversation_participants.membership_id')
                ->join('users as u', 'u.id', '=', 'om.user_id')
                ->where('conversation_participants.conversation_id', $conversation->id)->whereNull('conversation_participants.left_at')
                ->orderBy('u.name')->get(['conversation_participants.membership_id', 'u.name', 'u.last_seen_at']);
            $count = $people->count();

            if ($conversation->kind === ConversationKind::Group) {
                $title = (string) $conversation->title;
                $members = $people->pluck('name')->all();
                $memberIds = $people->pluck('membership_id')->all();
            } else {
                $other = $people->firstWhere(fn ($p) => $p->membership_id !== $member->id);
                $title = $other?->name ?? 'Unknown';
                $online = Presence::isOnline($other?->last_seen_at);
            }
        }

        return new ThreadPage(
            conversation: $conversation,
            title: $title,
            online: $online,
            memberCount: $count,
            members: $members,
            memberIds: $memberIds,
            messages: $messages,
            earlier: $hasEarlier && $messages !== [] ? $messages[0]->cursor : null,
            readUntil: $this->readUntil($conversation, $member),
            latest: $messages === [] ? null : end($messages)->cursor,
        );
    }

    public static function cursor(Message $m): string
    {
        return $m->created_at->utc()->format('Y-m-d\TH:i:s.u\Z').'_'.$m->id;
    }

    /** @return array{0: string, 1: string}|null */
    public static function parse(?string $cursor): ?array
    {
        if ($cursor !== null && preg_match('/^(\d{4}-\d{2}-\d{2}T[0-9:.]+Z)_([0-9a-f-]{36})$/', $cursor, $m) === 1 && Str::isUuid($m[2])) {
            return [$m[1], $m[2]];
        }

        return null;
    }
}
