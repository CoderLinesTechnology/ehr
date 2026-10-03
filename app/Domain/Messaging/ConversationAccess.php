<?php

namespace App\Domain\Messaging;

use App\Domain\Clients\ClientVisibility;
use App\Domain\Identity\PermissionResolver;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may open a conversation. One rule, used by the inbox, the thread, every action and the policy:
 *
 *   - an ACTIVE member of the organization who is a CURRENT participant (leaving ends access), and
 *   - holds the kind's permission (team threads: messages.send, client threads: messages.client), and
 *   - for a client thread, may still see that client (ClientVisibility).
 *
 * Losing a permission or visibility hides the thread without anyone editing it.
 */
final class ConversationAccess
{
    public static function permits(OrganizationMembership $membership, ConversationKind $kind): bool
    {
        return $membership->isActive() && app(PermissionResolver::class)->membershipHas($membership, $kind->permission());
    }

    /** The member's active participant row, or null. */
    public static function participant(Conversation $conversation, OrganizationMembership $membership): ?ConversationParticipant
    {
        return ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('membership_id', $membership->id)
            ->whereNull('left_at')
            ->first();
    }

    public static function allows(Conversation $conversation, OrganizationMembership $membership): bool
    {
        return $conversation->organization_id === $membership->organization_id
            && self::permits($membership, $conversation->kind)
            && self::participant($conversation, $membership) !== null
            && self::clientVisible($conversation, $membership);
    }

    /** May this member take part in (be added to) a thread of this kind, about this client? */
    public static function mayJoin(OrganizationMembership $membership, ConversationKind $kind, ?Client $client): bool
    {
        if (! self::permits($membership, $kind)) {
            return false;
        }

        return $kind !== ConversationKind::Client || ($client !== null && ClientVisibility::allows($client, $membership));
    }

    private static function clientVisible(Conversation $conversation, OrganizationMembership $membership): bool
    {
        if ($conversation->kind !== ConversationKind::Client) {
            return true;
        }

        $client = Client::query()->find($conversation->client_id);

        return $client !== null && ClientVisibility::allows($client, $membership);
    }

    /**
     * Restrict a `conversations` query to threads of the right kind for the member's permissions, and
     * client threads to clients they may see. (The participant join is the caller's.)
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public static function restrict(Builder $query, OrganizationMembership $membership): Builder
    {
        if (! $membership->isActive()) {
            return $query->whereRaw('1 = 0');
        }

        $permissions = app(PermissionResolver::class);
        $team = $permissions->membershipHas($membership, 'messages.send');
        $clients = $permissions->membershipHas($membership, 'messages.client');

        if (! $team && ! $clients) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $kinds) use ($team, $clients, $membership) {
            if ($team) {
                $kinds->orWhereIn('conversations.kind', ['direct', 'group']);
            }
            if ($clients) {
                $kinds->orWhere(function (Builder $client) use ($membership) {
                    $client->where('conversations.kind', 'client')
                        ->whereIn('conversations.client_id', ClientVisibility::apply(Client::query(), $membership)->select('clients.id'));
                });
            }
        });
    }
}
