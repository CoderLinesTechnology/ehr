<?php

namespace App\Models;

use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\Modality;
use App\Domain\Shared\RecordEnvironment;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Owned by the Scheduling domain. Times, status, price, environment and the
 * participants are written only by ScheduleAppointment / TransitionAppointment /
 * RescheduleAppointment; only the free-text scheduling note is mass-assignable.
 */
#[Fillable(['scheduling_notes'])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'record_environment' => RecordEnvironment::class,
            'status' => AppointmentStatus::class,
            'modality' => Modality::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'allow_overlap' => 'boolean',
            'late_cancellation' => 'boolean',
            'price_minor' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'checked_in_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'no_show_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<OrganizationMembership, $this> */
    public function clinician(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'clinician_membership_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<self, $this> */
    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    /** @return HasOne<self, $this> */
    public function rescheduledTo(): HasOne
    {
        return $this->hasOne(self::class, 'rescheduled_from_id');
    }

    /** @return HasMany<AppointmentStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(AppointmentStatusHistory::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function isDemo(): bool
    {
        return $this->record_environment === RecordEnvironment::Demo;
    }

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    /** Start time in the appointment's own timezone. */
    public function localStart(): \Carbon\CarbonImmutable
    {
        return $this->starts_at->setTimezone($this->timezone);
    }

    public function localEnd(): \Carbon\CarbonImmutable
    {
        return $this->ends_at->setTimezone($this->timezone);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('record_environment'), RecordEnvironment::Live->value);
    }

    public function scopeOccupying(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), AppointmentStatus::OCCUPYING);
    }

    /** Overlapping [from, to) — the calendar's range query. */
    public function scopeOverlapping(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->where($query->qualifyColumn('starts_at'), '<', $to)
            ->where($query->qualifyColumn('ends_at'), '>', $from);
    }
}
