<?php

namespace App\Models;

use App\Domain\Shared\InsertOnly;
use App\Domain\Shared\RecordEnvironment;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Client timeline projection. Written by event listeners (RecordTimelineEntry),
 * filtered by category against the reader's permissions.
 */
#[Table(timestamps: false)]
class TimelineEntry extends Model
{
    use BelongsToOrganization, HasUuids, InsertOnly;

    public const CATEGORIES = ['administrative', 'scheduling', 'clinical', 'financial', 'communication', 'program', 'document'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'record_environment' => RecordEnvironment::class,
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
