<?php

namespace App\Models;

use App\Domain\Programs\ProgramColor;
use App\Domain\Programs\ProgramStatus;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A structured treatment or support program. Status, the 42 CFR Part 2 flag and the creator are written by the
 * Programs domain actions only (not mass-assignable).
 */
#[Fillable(['name', 'description', 'icon', 'color', 'starts_on', 'ends_on', 'location_id', 'is_online'])]
class Program extends Model
{
    use BelongsToOrganization, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => ProgramStatus::class,
            'color' => ProgramColor::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'is_online' => 'boolean',
            'is_sud_program' => 'boolean',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<LevelOfCare, $this> */
    public function levels(): HasMany
    {
        return $this->hasMany(LevelOfCare::class)->orderBy('sort')->orderBy('name');
    }

    /** @return HasMany<ProgramStaff, $this> */
    public function staff(): HasMany
    {
        return $this->hasMany(ProgramStaff::class);
    }

    /** @return HasMany<ProgramEnrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(ProgramEnrollment::class);
    }

    /** @return HasMany<ProgramSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(ProgramSession::class);
    }

    /** "Accra", "Online" or "—". Needs `location` loaded when the program has one. */
    public function placeLabel(): string
    {
        return $this->is_online ? 'Online' : ($this->location_id !== null ? ($this->location?->name ?? '—') : '—');
    }

    /** "Jan 15, 2025 – Ongoing" / "May 5, 2025 – Jul 30, 2025" (organization date format). */
    public function dateRange(): string
    {
        return fmt()->date($this->starts_on).' – '.($this->ends_on !== null ? fmt()->date($this->ends_on) : 'Ongoing');
    }
}
