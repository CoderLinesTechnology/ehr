<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A group session or activity on a program's schedule. Written by ScheduleProgramSession / CancelProgramSession only. */
class ProgramSession extends Model
{
    use BelongsToOrganization, HasUuids;

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'is_online' => 'boolean',
        ];
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<OrganizationMembership, $this> */
    public function facilitator(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'facilitator_membership_id');
    }

    /** @return HasMany<ProgramSessionAttendance, $this> */
    public function attendance(): HasMany
    {
        return $this->hasMany(ProgramSessionAttendance::class, 'session_id');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function placeLabel(): string
    {
        return $this->is_online ? 'Online' : ($this->location?->name ?? '—');
    }
}
