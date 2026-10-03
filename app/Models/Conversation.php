<?php

namespace App\Models;

use App\Domain\Messaging\ConversationKind;
use App\Domain\Shared\RecordEnvironment;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A direct, group or client thread. Everything is written by the Messaging domain actions
 * (no mass assignment); membership of the thread is conversation_participants.
 */
class Conversation extends Model
{
    use BelongsToOrganization, HasUuids;

    /** Microsecond precision and an explicit offset: the inbox sorts and pages by this instant. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'kind' => ConversationKind::class,
            'record_environment' => RecordEnvironment::class,
            'last_message_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<ConversationParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isDemo(): bool
    {
        return $this->record_environment === RecordEnvironment::Demo;
    }
}
