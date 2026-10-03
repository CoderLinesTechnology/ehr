<?php

namespace App\Models;

use App\Domain\Shared\RecordEnvironment;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A couple record's link to one of its two members. Composite foreign keys keep both ends in the same
 * organization AND environment; written by the Clients domain only (CreateCouple, UpdateClient).
 */
class ClientCoupleMember extends Model
{
    use BelongsToOrganization, HasUuids;

    /** Every column is a reference or the environment: nothing is mass-assignable. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['record_environment' => RecordEnvironment::class];
    }

    /** @return BelongsTo<Client, $this> */
    public function couple(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'couple_client_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'member_client_id');
    }
}
