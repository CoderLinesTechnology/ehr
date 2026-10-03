<?php

namespace App\Domain\Messaging;

use App\Domain\Audit\AuditLogger;
use App\Domain\Clients\ClientStatus;
use App\Domain\Clients\ClientVisibility;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\RecordEnvironment;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\OrganizationMembership;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts a direct (one per pair of staff), group, or client thread (one per client and staff member;
 * the client's side arrives with the portal). Asking again for an existing direct/client thread
 * returns it (`wasRecentlyCreated` is false) so a double click cannot create two.
 */
final class StartConversation
{
    public const MAX_GROUP = 50;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<string>  $withMembershipIds  other staff (direct: exactly one; group: 1+; client: optional)
     */
    public function __invoke(
        OrganizationMembership $actor,
        ConversationKind $kind,
        array $withMembershipIds = [],
        ?string $title = null,
        ?Client $client = null,
    ): Conversation {
        if (! ConversationAccess::permits($actor, $kind)) {
            throw new DomainException('You are not allowed to start this kind of conversation.', 'messaging_forbidden');
        }

        $others = $this->others($actor, $kind, $client, $withMembershipIds);
        $title = $kind === ConversationKind::Group ? trim((string) $title) : null;

        match ($kind) {
            ConversationKind::Direct => count($others) === 1 || throw new DomainException('Choose one person to message.', 'direct_needs_one', 'participants'),
            ConversationKind::Group => $this->checkGroup($title, count($others)),
            ConversationKind::Client => $this->checkClient($actor, $client),
        };

        $key = match ($kind) {
            ConversationKind::Direct => 'd:'.collect([$actor->id, $others[0]->id])->sort()->implode('|'),
            ConversationKind::Client => 'c:'.$client->id.'|'.$actor->id,
            ConversationKind::Group => null,
        };

        if ($key !== null && ($existing = Conversation::query()->where('unique_key', $key)->first()) !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => $this->create($actor, $kind, $others, $title, $client, $key));
        } catch (UniqueConstraintViolationException) {
            // Lost a race with the same request from a second tab: the thread exists now.
            return Conversation::query()->where('unique_key', $key)->firstOrFail();
        }
    }

    /** @param list<OrganizationMembership> $others */
    private function create(OrganizationMembership $actor, ConversationKind $kind, array $others, ?string $title, ?Client $client, ?string $key): Conversation
    {
        $now = now()->utc();

        $conversation = new Conversation;
        $conversation->forceFill([
            'kind' => $kind,
            'title' => $title,
            'client_id' => $client?->id,
            'record_environment' => $client?->record_environment ?? RecordEnvironment::Live,
            'unique_key' => $key,
            'created_by_membership_id' => $actor->id,
            'last_message_at' => $now,
        ])->save();

        foreach ([$actor, ...$others] as $member) {
            $participant = new ConversationParticipant;
            $participant->forceFill(['conversation_id' => $conversation->id, 'membership_id' => $member->id, 'joined_at' => $now])->save();
        }

        $this->audit->record('conversation.started', $conversation, metadata: ['kind' => $kind->value, 'participants' => count($others) + 1], summary: 'Conversation started');

        return $conversation;
    }

    /** @return list<OrganizationMembership> the other participants, each allowed to take part */
    private function others(OrganizationMembership $actor, ConversationKind $kind, ?Client $client, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => is_string($id) && Str::isUuid($id) && $id !== $actor->id)));
        if ($ids === []) {
            return [];
        }

        $members = OrganizationMembership::query()->whereIn('id', $ids)->get();
        if ($members->count() !== count($ids) || $members->contains(fn (OrganizationMembership $m) => ! ConversationAccess::mayJoin($m, $kind, $client))) {
            throw new DomainException('One of the people you chose cannot be added to this conversation.', 'participant_not_allowed', 'participants');
        }

        return $members->all();
    }

    private function checkGroup(string $title, int $others): void
    {
        if ($title === '' || mb_strlen($title) > 120) {
            throw new DomainException('Give the group a name of up to 120 characters.', 'group_title', 'title');
        }
        if ($others < 1 || $others + 1 > self::MAX_GROUP) {
            throw new DomainException('A group needs at least one other person, and at most '.self::MAX_GROUP.' people.', 'group_size', 'participants');
        }
    }

    private function checkClient(OrganizationMembership $actor, ?Client $client): void
    {
        if ($client === null || ! ClientVisibility::allows($client, $actor)) {
            throw new DomainException('Choose a client you can see.', 'client_not_visible', 'client');
        }
        if ($client->status === ClientStatus::Archived) {
            throw new DomainException('Archived clients cannot be messaged.', 'client_archived', 'client');
        }
    }
}
