<?php

namespace App\Models;

use App\Domain\Shared\InsertOnly;
use App\Domain\Shared\RecordEnvironment;
use App\Domain\Telehealth\SessionStatus;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Table(timestamps: false)]
class TelehealthSessionStatusHistory extends Model
{
    use BelongsToOrganization, HasUuids, InsertOnly;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'record_environment' => RecordEnvironment::class,
            'from_status' => SessionStatus::class,
            'to_status' => SessionStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
