<?php

namespace App\Models;

use App\Domain\Shared\RecordEnvironment;
use App\Domain\Telehealth\TranscriptSource;
use App\Domain\Telehealth\TranscriptStatus;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A transcript is a draft until a clinician marks it reviewed (ReviewTranscript); nothing finalises it automatically. */
class SessionTranscript extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['body'];

    protected function casts(): array
    {
        return [
            'record_environment' => RecordEnvironment::class,
            'consented' => 'boolean',
            'source' => TranscriptSource::class,
            'status' => TranscriptStatus::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TelehealthSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TelehealthSession::class, 'telehealth_session_id');
    }

    public function isDraft(): bool
    {
        return $this->status === TranscriptStatus::Draft;
    }

    /** "AI Transcript (Draft)" / "Transcript" as shown to people. */
    public function label(): string
    {
        $base = $this->source === TranscriptSource::Ai ? 'AI Transcript' : 'Transcript';

        return $this->isDraft() ? $base.' (Draft)' : $base;
    }
}
