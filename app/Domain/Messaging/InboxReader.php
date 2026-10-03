<?php

namespace App\Domain\Messaging;

use App\Models\Client;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The conversation list for one member: tabs with unread counts, search by participant name or group
 * title (message bodies are NOT searched), keyset pagination. A constant number of queries per page:
 * the page of conversations (unread counted in SQL), the other person of each direct thread, the
 * clients, and the last message of each.
 */
final class InboxReader
{
    public const PAGE = 30;

    private const TABS = ['all' => null, 'clients' => 'client', 'team' => 'direct', 'groups' => 'group'];

    public function page(OrganizationMembership $member, string $tab = 'all', ?string $q = null, ?string $after = null, bool $unreadOnly = false): InboxPage
    {
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'all';
        $q = $q === null ? '' : trim(mb_substr($q, 0, 100));

        $query = UnreadCount::visible($member)
            ->select(['conversations.id', 'conversations.kind', 'conversations.title', 'conversations.client_id', 'conversations.last_message_at', 'p.joined_at'])
            ->selectRaw(UnreadCount::messagesSql().' as unread');

        if (self::TABS[$tab] !== null) {
            $query->where('conversations.kind', self::TABS[$tab]);
        }
        if ($unreadOnly) {
            $query->whereRaw(UnreadCount::messagesSql().' > 0');
        }
        if ($q !== '') {
            $this->search($query, $member, $q);
        }
        if ($after !== null && preg_match('/^(\d{4}-\d{2}-\d{2}T[0-9:.]+Z)_([0-9a-f-]{36})$/', $after, $m) === 1 && Str::isUuid($m[2])) {
            $query->whereRaw('(conversations.last_message_at, conversations.id) < (?::timestamptz, ?::uuid)', [$m[1], $m[2]]);
        }

        $found = $query->orderByDesc('conversations.last_message_at')->orderByDesc('conversations.id')->limit(self::PAGE + 1)->get();
        $more = $found->count() > self::PAGE;
        $found = $found->take(self::PAGE);

        $rows = $this->rows($member, $found);

        return new InboxPage(
            $rows,
            $more && $rows !== [] ? end($rows)->cursor : null,
            $this->unreadByTab($member),
            $tab,
            $q,
            $unreadOnly,
        );
    }

    /** @return array{all: int, clients: int, team: int, groups: int} */
    public function unreadByTab(OrganizationMembership $member): array
    {
        $byKind = UnreadCount::summary($member)['byKind'];

        return [
            'all' => array_sum($byKind),
            'clients' => $byKind['client'] ?? 0,
            'team' => $byKind['direct'] ?? 0,
            'groups' => $byKind['group'] ?? 0,
        ];
    }

    private function search($query, OrganizationMembership $member, string $q): void
    {
        $like = '%'.addcslashes($q, '\\%_').'%';

        $query->where(function ($w) use ($like, $member) {
            $w->where('conversations.title', 'ilike', $like)
                ->orWhereExists(function ($e) use ($like, $member) {
                    $e->selectRaw('1')->from('conversation_participants as op')
                        ->join('organization_memberships as om', 'om.id', '=', 'op.membership_id')
                        ->join('users as u', 'u.id', '=', 'om.user_id')
                        ->whereColumn('op.conversation_id', 'conversations.id')
                        ->where('op.organization_id', $member->organization_id)
                        ->where('op.membership_id', '<>', $member->id)
                        ->where('u.name', 'ilike', $like);
                })
                ->orWhereExists(function ($e) use ($like, $member) {
                    $e->selectRaw('1')->from('clients as c')
                        ->whereColumn('c.id', 'conversations.client_id')
                        ->where('c.organization_id', $member->organization_id)
                        ->whereRaw("(c.first_name || ' ' || c.last_name) ilike ?", [$like]);
                });
        });
    }

    /** @return list<InboxRow> */
    private function rows(OrganizationMembership $member, $found): array
    {
        if ($found->isEmpty()) {
            return [];
        }

        $ids = $found->pluck('id')->all();

        // The other person of each direct thread (name and last seen).
        $others = ConversationParticipant::query()
            ->join('organization_memberships as om', 'om.id', '=', 'conversation_participants.membership_id')
            ->join('users as u', 'u.id', '=', 'om.user_id')
            ->whereIn('conversation_participants.conversation_id', $found->where('kind', ConversationKind::Direct)->pluck('id')->all())
            ->where('conversation_participants.membership_id', '<>', $member->id)
            ->get(['conversation_participants.conversation_id', 'u.name', 'u.last_seen_at'])
            ->keyBy('conversation_id');

        $clients = Client::query()->whereIn('id', $found->pluck('client_id')->filter()->all())->get(['id', 'first_name', 'last_name'])->keyBy('id');

        // Last visible message of each thread (not before the member joined), with its sender's first name.
        $last = collect(DB::select(
            'select distinct on (m.conversation_id) m.conversation_id, m.sender_membership_id, m.retracted_at, left(m.body, 160) as body,
                    exists (select 1 from message_attachments a where a.message_id = m.id and a.purged_at is null) as has_file, u.name as sender
               from messages m
               join conversation_participants p on p.conversation_id = m.conversation_id and p.membership_id = ? and p.organization_id = ?
               join organization_memberships sm on sm.id = m.sender_membership_id
               join users u on u.id = sm.user_id
              where m.organization_id = ? and m.conversation_id in ('.implode(',', array_fill(0, count($ids), '?')).') and m.created_at >= p.joined_at
              order by m.conversation_id, m.created_at desc, m.id desc',
            [$member->id, $member->organization_id, $member->organization_id, ...$ids],
        ))->keyBy('conversation_id');

        $rows = [];
        foreach ($found as $c) {
            $name = match ($c->kind) {
                ConversationKind::Direct => $others->get($c->id)?->name ?? 'Unknown',
                ConversationKind::Group => (string) $c->title,
                ConversationKind::Client => ($client = $clients->get($c->client_id)) ? $client->fullName() : 'Client',
            };
            $at = $c->last_message_at;

            $rows[] = new InboxRow(
                id: $c->id,
                kind: $c->kind,
                name: $name,
                unread: (int) $c->unread,
                preview: $this->preview($last->get($c->id), $c->kind, $member),
                at: $at,
                online: $c->kind === ConversationKind::Direct && Presence::isOnline($others->get($c->id)?->last_seen_at),
                cursor: $at->utc()->format('Y-m-d\TH:i:s.u\Z').'_'.$c->id,
            );
        }

        return $rows;
    }

    private function preview(?object $message, ConversationKind $kind, OrganizationMembership $member): string
    {
        if ($message === null) {
            return 'No messages yet';
        }
        if ($message->retracted_at !== null) {
            return 'Message removed';
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $message->body));
        if ($text === '') {
            $text = $message->has_file ? 'Sent an attachment' : '';
        }

        // Groups say who spoke ("James: …"); "You" for the member's own last message.
        if ($kind === ConversationKind::Group) {
            $who = $message->sender_membership_id === $member->id ? 'You' : Str::before((string) $message->sender, ' ');

            return $who.': '.$text;
        }

        return $message->sender_membership_id === $member->id ? 'You: '.$text : $text;
    }
}
