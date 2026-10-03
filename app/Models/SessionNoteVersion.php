<?php

namespace App\Models;

use App\Domain\Shared\InsertOnly;
use App\Domain\Shared\RecordEnvironment;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table(timestamps: false)]
class SessionNoteVersion extends Model
{
    use BelongsToOrganization, HasUuids, InsertOnly;

    protected $guarded = ['*'];

    protected $hidden = ['body'];

    protected function casts(): array
    {
        return ['record_environment' => RecordEnvironment::class, 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
