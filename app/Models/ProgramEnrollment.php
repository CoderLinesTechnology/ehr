<?php

namespace App\Models;

use App\Domain\Programs\EnrollmentStatus;
use App\Domain\Shared\RecordEnvironment;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One client's enrollment in one program. Nothing here is mass-assignable: status, level and environment are
 * written by the Programs domain actions, and every change leaves an insert-only ProgramEnrollmentEvent.
 */
class ProgramEnrollment extends Model
{
    use BelongsToOrganization, HasUuids;

    protected function casts(): array
    {
        return [
            'record_environment' => RecordEnvironment::class,
            'status' => EnrollmentStatus::class,
            'admitted_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return BelongsTo<LevelOfCare, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(LevelOfCare::class, 'current_level_id');
    }

    /** @return HasMany<ProgramEnrollmentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ProgramEnrollmentEvent::class, 'enrollment_id')->orderBy('seq');
    }
}
