<?php

namespace App\Domain\Messaging;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\OrganizationMembership;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Appends a message (text up to 5000 characters and/or one attachment) as a current participant. */
final class SendMessage
{
    public const MAX_BODY = 5000;

    public function __construct(private readonly AuditLogger $audit, private readonly AttachmentStore $files) {}

    public function __invoke(OrganizationMembership $sender, Conversation $conversation, string $body, ?UploadedFile $attachment = null): Message
    {
        $body = trim(str_replace("\r\n", "\n", $body));

        if ($body === '' && $attachment === null) {
            throw new DomainException('Write a message or attach a file.', 'message_empty', 'body');
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            throw new DomainException('Messages can be up to '.number_format(self::MAX_BODY).' characters.', 'message_too_long', 'body');
        }
        if (! ConversationAccess::allows($conversation, $sender)) {
            throw new DomainException('You can no longer send messages in this conversation.', 'messaging_forbidden');
        }

        $file = $attachment !== null ? $this->files->inspect($attachment) : null;
        $path = $file !== null ? $this->files->put($attachment, $conversation->organization_id, $conversation->id, $file['extension']) : null;

        try {
            return DB::transaction(function () use ($sender, $conversation, $body, $file, $path) {
                $sentAt = now()->utc();

                $message = new Message;
                $message->forceFill([
                    'conversation_id' => $conversation->id,
                    'sender_membership_id' => $sender->id,
                    'body' => $body,
                    'created_at' => $sentAt,
                ])->save();

                if ($file !== null) {
                    $row = new MessageAttachment;
                    $row->forceFill([
                        'conversation_id' => $conversation->id,
                        'message_id' => $message->id,
                        'original_name' => $file['name'],
                        'mime' => $file['mime'],
                        'size_bytes' => $file['size'],
                        'storage_path' => $path,
                        'sha256' => $file['sha256'],
                    ])->save();
                }

                $conversation->forceFill(['last_message_at' => $sentAt])->save();

                // Your own message is read by you.
                ConversationParticipant::query()->where('conversation_id', $conversation->id)->where('membership_id', $sender->id)
                    ->first()?->forceFill(['last_read_at' => $sentAt, 'last_read_message_id' => $message->id])->save();

                // Metadata only: the words never reach the audit trail.
                $this->audit->record('message.sent', $message, metadata: [
                    'kind' => $conversation->kind->value, 'length' => mb_strlen($body), 'attachments' => $file !== null ? 1 : 0,
                ], summary: 'Message sent');

                return $message;
            });
        } catch (\Throwable $e) {
            if ($path !== null) {
                $this->files->forget($path);
            }
            throw $e;
        }
    }
}
