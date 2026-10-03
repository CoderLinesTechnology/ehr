<?php

namespace App\Models;

use App\Domain\Shared\RecordEnvironment;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Metadata of a consented recording; the file is on the private disk and is served only through an authorized, audited route. */
class SessionRecording extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return [
            'record_environment' => RecordEnvironment::class,
            'consented' => 'boolean',
            'size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'purged_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TelehealthSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TelehealthSession::class, 'telehealth_session_id');
    }
}
