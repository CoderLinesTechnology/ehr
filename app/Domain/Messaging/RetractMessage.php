<?php

namespace App\Domain\Messaging;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;

/**
 * The sender may take a message back for a few minutes. Nothing is deleted: the words (and any
 * file) are removed and a "message removed" tombstone stays in the thread. The database refuses any other rewrite.
 */
final class RetractMessage
{
    public const WINDOW_MINUTES = 5;

    public function __construct(private readonly AuditLogger $audit, private readonly AttachmentStore $files) {}

    public function __invoke(OrganizationMembership $member, Message $message): Message
    {
        $paths = DB::transaction(function () use ($member, $message) {
            $locked = Message::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();

            if ($locked->sender_membership_id !== $member->id || ! ConversationAccess::allows($locked->conversation()->firstOrFail(), $member)) {
                throw new DomainException('Only the sender can remove a message.', 'retract_forbidden');
            }
            if ($locked->isRetracted()) {
                throw new DomainException('This message was already removed.', 'retract_done');
            }
            if ($locked->created_at->addMinutes(self::WINDOW_MINUTES)->isPast()) {
                throw new DomainException('A message can only be removed within '.self::WINDOW_MINUTES.' minutes of sending it.', 'retract_window');
            }

            $afterSeconds = (int) $locked->created_at->diffInSeconds(now(), true);
            $now = now()->utc();
            $locked->forceFill(['body' => '', 'retracted_at' => $now, 'updated_at' => $now])->save();

            $paths = [];
            MessageAttachment::query()->where('message_id', $locked->id)->whereNull('purged_at')->get()->each(function (MessageAttachment $a) use (&$paths, $now) {
                $paths[] = $a->storage_path;
                $a->forceFill(['purged_at' => $now])->save();
            });

            $this->audit->record('message.retracted', $locked, metadata: ['seconds_after_send' => $afterSeconds], summary: 'Message removed by its sender');
            $message->setRawAttributes($locked->getAttributes(), true);

            return $paths;
        });

        foreach ($paths as $path) {
            $this->files->forget($path);   // after commit: a rolled-back retraction must keep its file
        }

        return $message;
    }
}
