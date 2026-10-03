<?php

namespace App\Models;

use App\Domain\Platform\OrganizationStatus;
use App\Domain\Shared\InsertOnly;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table(timestamps: false)]
class OrganizationStatusHistory extends Model
{
    use HasUuids, InsertOnly;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'from_status' => OrganizationStatus::class,
            'to_status' => OrganizationStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
