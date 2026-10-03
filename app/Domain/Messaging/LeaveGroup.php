<?php

namespace App\Domain\Messaging;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Conversation;
use App\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;

/** Leaves a group: access ends at once, the member's past messages stay (the row is kept, not deleted). */
final class LeaveGroup
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(OrganizationMembership $member, Conversation $conversation): void
    {
        if ($conversation->kind !== ConversationKind::Group) {
            throw new DomainException('Only group conversations can be left.', 'leave_group_only');
        }

        DB::transaction(function () use ($member, $conversation) {
            $participant = ConversationAccess::participant($conversation, $member);
            if ($participant === null) {
                throw new DomainException('You are not in this conversation.', 'not_participant');
            }

            $participant->forceFill(['left_at' => now()->utc()])->save();
            $this->audit->record('conversation.left', $conversation, metadata: ['kind' => 'group'], summary: 'Left a group conversation');
        });
    }
}
