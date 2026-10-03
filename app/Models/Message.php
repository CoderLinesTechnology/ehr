<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Never deleted; the sender may retract it within a few minutes (body emptied, tombstone kept). */
class Message extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'retracted_at' => 'immutable_datetime',
            'edited_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<OrganizationMembership, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'sender_membership_id');
    }

    /** @return HasMany<MessageReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    /** @return HasMany<MessageAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function isRetracted(): bool
    {
        return $this->retracted_at !== null;
    }
}
