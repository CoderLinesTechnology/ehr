<?php

namespace App\Models;

use App\Domain\Shared\RecordEnvironment;
use App\Domain\Telehealth\SessionStatus;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One video session per telehealth appointment. Status, link, times, consent and environment are written only
 * by the Telehealth domain actions (nothing is mass-assignable). The meeting link is encrypted at rest.
 */
class TelehealthSession extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['join_url'];

    protected function casts(): array
    {
        return [
            'record_environment' => RecordEnvironment::class,
            'status' => SessionStatus::class,
            'join_url' => 'encrypted',
            'consent_to_record' => 'boolean',
            'duration_minutes' => 'integer',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'consent_recorded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<OrganizationMembership, $this> */
    public function clinician(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'clinician_membership_id');
    }

    /** @return HasMany<TelehealthSessionStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(TelehealthSessionStatusHistory::class)->orderBy('occurred_at')->orderBy('id');
    }

    /** @return HasMany<SessionRecording, $this> */
    public function recordings(): HasMany
    {
        return $this->hasMany(SessionRecording::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<SessionTranscript, $this> */
    public function transcripts(): HasMany
    {
        return $this->hasMany(SessionTranscript::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasOne<SessionNote, $this> */
    public function note(): HasOne
    {
        return $this->hasOne(SessionNote::class);
    }

    public function isDemo(): bool
    {
        return $this->record_environment === RecordEnvironment::Demo;
    }
}
