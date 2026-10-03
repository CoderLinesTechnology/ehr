<?php

namespace App\Models;

use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Shared\InsertOnly;
use App\Domain\Shared\RecordEnvironment;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table(timestamps: false)]
class AppointmentStatusHistory extends Model
{
    use BelongsToOrganization, HasUuids, InsertOnly;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'record_environment' => RecordEnvironment::class,
            'from_status' => AppointmentStatus::class,
            'to_status' => AppointmentStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
