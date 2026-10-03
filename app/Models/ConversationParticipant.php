<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Membership of a thread. Rows are never deleted: leaving stamps left_at, so history keeps its authors. */
class ConversationParticipant extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'joined_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
            'last_read_at' => 'immutable_datetime',
            'muted' => 'boolean',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<OrganizationMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class);
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }
}
