<?php

namespace App\Models;

use App\Domain\Shared\RecordEnvironment;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** The stable identity of a session's summary note; the text lives in insert-only SessionNoteVersion rows. */
class SessionNote extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['record_environment' => RecordEnvironment::class, 'latest_version' => 'integer'];
    }

    /** @return HasMany<SessionNoteVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(SessionNoteVersion::class)->orderByDesc('version');
    }

    /** @return HasOne<SessionNoteVersion, $this> */
    public function current(): HasOne
    {
        return $this->hasOne(SessionNoteVersion::class)->ofMany('version', 'max');
    }
}
