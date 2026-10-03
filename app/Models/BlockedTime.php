<?php

namespace App\Models;

use App\Domain\Scheduling\BlockedTimeKind;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\BlockedTimeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Time nobody (membership_id NULL) or one clinician can be booked: leave, holidays, blocks. */
#[Fillable(['membership_id', 'location_id', 'kind', 'title', 'starts_at', 'ends_at', 'all_day'])]
class BlockedTime extends Model
{
    /** @use HasFactory<BlockedTimeFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'kind' => BlockedTimeKind::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'all_day' => 'boolean',
        ];
    }

    /** @return BelongsTo<OrganizationMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'membership_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
