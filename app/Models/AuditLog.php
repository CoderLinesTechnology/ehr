<?php

namespace App\Models;

use App\Domain\Shared\InsertOnly;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Written only by AuditLogger. Insert-only in the model and in PostgreSQL. */
#[Table(timestamps: false)]
class AuditLog extends Model
{
    use HasUuids, InsertOnly;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function scopeForOrganization(Builder $query, string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId)->where('context', '!=', 'platform');
    }

    /** What a platform administrator may see: platform actions, never tenant activity. */
    public function scopePlatformVisible(Builder $query): Builder
    {
        return $query->whereIn('context', ['platform', 'system']);
    }
}
