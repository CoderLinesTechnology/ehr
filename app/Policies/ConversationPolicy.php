<?php

namespace App\Policies;

use App\Domain\Messaging\ConversationAccess;
use App\Domain\Messaging\ConversationKind;
use App\Domain\Tenancy\TenantContext;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * 403 when the member lacks messaging permissions altogether; 404 for any conversation they are not a
 * participant of (or may no longer see), so a thread's existence is never revealed. The rule is ConversationAccess.
 */
final class ConversationPolicy
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** The inbox: any messaging permission. */
    public function viewAny(User $user): Response
    {
        $member = $this->tenant->membership();
        if ($member === null) {
            return Response::denyAsNotFound();
        }

        return ConversationAccess::permits($member, ConversationKind::Direct) || ConversationAccess::permits($member, ConversationKind::Client)
            ? Response::allow() : Response::deny();
    }

    public function view(User $user, Conversation $conversation): Response
    {
        $member = $this->tenant->membership();

        return $member !== null && ConversationAccess::allows($conversation, $member) ? Response::allow() : Response::denyAsNotFound();
    }
}
