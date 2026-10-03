<?php

namespace App\Models;

use App\Domain\Clients\ClientStatus;
use App\Domain\Shared\RecordEnvironment;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The person receiving care. record_environment, client_number and status are
 * written by the Clients domain actions only (not mass-assignable).
 */
#[Fillable([
    'first_name', 'middle_name', 'last_name', 'preferred_name', 'date_of_birth', 'sex',
    'gender_identity', 'pronouns', 'email', 'phone', 'preferred_contact_method',
    'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country_code', 'timezone',
    'primary_clinician_membership_id', 'primary_location_id', 'referral_source', 'administrative_notes',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'record_environment' => RecordEnvironment::class,
            'status' => ClientStatus::class,
            'client_number' => 'integer',
            'date_of_birth' => 'immutable_date',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<OrganizationMembership, $this> */
    public function primaryClinician(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'primary_clinician_membership_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function primaryLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'primary_location_id');
    }

    /** @return HasMany<ClientContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class)->orderBy('sort')->orderBy('name');
    }

    /** @return HasMany<Appointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** @return HasMany<TimelineEntry, $this> */
    public function timelineEntries(): HasMany
    {
        return $this->hasMany(TimelineEntry::class);
    }

    public function isDemo(): bool
    {
        return $this->record_environment === RecordEnvironment::Demo;
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** "Preferred Last" when a preferred name exists, else "First Last". */
    public function displayName(): string
    {
        return trim(($this->preferred_name ?: $this->first_name).' '.$this->last_name);
    }

    /** "CL-0012" (design spec); wider numbers simply grow. */
    public function formattedNumber(): string
    {
        return 'CL-'.str_pad((string) $this->client_number, 4, '0', STR_PAD_LEFT);
    }

    public function age(): ?int
    {
        return $this->date_of_birth?->age;
    }

    /** Real data only: reports, dashboard counts, limits and billing use this. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('record_environment'), RecordEnvironment::Live->value);
    }

    public function scopeDemo(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('record_environment'), RecordEnvironment::Demo->value);
    }
}
