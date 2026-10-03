<?php

namespace Tests\Feature\Telehealth;

use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\CancellationKind;
use App\Domain\Scheduling\TransitionAppointment;
use App\Domain\Shared\DomainException;
use App\Domain\Telehealth\AddTranscript;
use App\Domain\Telehealth\AttachRecording;
use App\Domain\Telehealth\EndSession;
use App\Domain\Telehealth\OpenSession;
use App\Domain\Telehealth\RecordConsent;
use App\Domain\Telehealth\ReviewTranscript;
use App\Domain\Telehealth\SaveSessionNotes;
use App\Domain\Telehealth\SessionStatus;
use App\Domain\Telehealth\TranscriptSource;
use App\Domain\Telehealth\TranscriptStatus;
use App\Domain\Tenancy\TenantMismatch;
use App\Models\Appointment;
use App\Models\SessionNoteVersion;
use App\Models\SessionRecording;
use App\Models\TelehealthSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/** The telehealth domain actions: join window, open/end, consent, notes, recordings, transcripts. */
class SessionActionsTest extends TelehealthTestCase
{
    // ── state machine ────────────────────────────────────────────────────────

    /** @return iterable<string, array{SessionStatus, SessionStatus, bool}> the allowed pairs, spelled out by hand */
    public static function pairs(): iterable
    {
        $allowed = [
            'scheduled' => ['waiting', 'in_progress', 'completed', 'cancelled', 'missed'],
            'waiting' => ['in_progress', 'completed', 'cancelled', 'missed'],
            'in_progress' => ['completed'],
            'completed' => [], 'cancelled' => [], 'missed' => [],
        ];
        foreach (SessionStatus::cases() as $from) {
            foreach (SessionStatus::cases() as $to) {
                yield "{$from->value} → {$to->value}" => [$from, $to, in_array($to->value, $allowed[$from->value], true)];
            }
        }
    }

    #[Test]
    #[DataProvider('pairs')]
    public function the_state_machine_allows_exactly_the_specified_transitions(SessionStatus $from, SessionStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }

    // ── join window, open, end ───────────────────────────────────────────────

    #[Test]
    public function a_session_can_be_opened_only_inside_the_join_window_and_it_starts_the_appointment(): void
    {
        $session = $this->sessionAt('2026-10-02 08:30:00'); // now is 08:00: 30 minutes away, window is 15

        try {
            app(OpenSession::class)($session, $this->actor);
            $this->fail('Opened outside the window.');
        } catch (DomainException $e) {
            $this->assertSame('outside_join_window', $e->errorCode());
        }

        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:16:00', 'UTC'));
        app(OpenSession::class)($session, $this->actor);

        $this->assertSame(SessionStatus::InProgress, $session->refresh()->status);
        $this->assertNotNull($session->started_at);
        $this->assertSame(AppointmentStatus::InProgress, Appointment::query()->findOrFail($session->appointment_id)->status);

        // Opening a running session again is a no-op, not an error.
        app(OpenSession::class)($session, $this->actor);
        $this->assertSame(1, $session->statusHistory()->where('to_status', 'in_progress')->count());
    }

    #[Test]
    public function the_join_window_follows_the_organizations_setting_and_closes_when_the_session_ends(): void
    {
        $this->setting('telehealth.join_early_minutes', 60);
        $session = $this->sessionAt('2026-10-02 08:45:00');
        app(OpenSession::class)($session, $this->actor);
        $this->assertSame(SessionStatus::InProgress, $session->refresh()->status);

        $late = $this->sessionAt('2026-10-02 08:00:00', $this->drB, $this->clientB);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 09:05:00', 'UTC')); // an hour-long session that is over
        $this->expectException(DomainException::class);
        app(OpenSession::class)($late, $this->actor);
    }

    #[Test]
    public function ending_a_running_session_completes_it_and_its_appointment_with_the_duration(): void
    {
        $session = $this->sessionAt('2026-10-02 08:10:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:10:00', 'UTC'));
        app(OpenSession::class)($session, $this->actor);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:55:00', 'UTC'));

        app(EndSession::class)($session, $this->actor);

        $this->assertSame(SessionStatus::Completed, $session->refresh()->status);
        $this->assertSame(45, $session->duration_minutes);
        $this->assertSame(AppointmentStatus::Completed, Appointment::query()->findOrFail($session->appointment_id)->status);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'telehealth.session_status_changed')->where('subject_id', $session->id)->where('after->status', 'completed')->count());
    }

    #[Test]
    public function only_a_running_session_can_be_ended(): void
    {
        $session = $this->sessionAt('2026-10-06 10:00:00');

        $this->expectException(DomainException::class);
        app(EndSession::class)($session, $this->actor);
    }

    #[Test]
    public function an_action_refuses_another_organizations_session(): void
    {
        $foreign = $this->inTenant($this->other, function () {
            $appointment = Appointment::query()->findOrFail($this->otherAppointment->id);

            return TelehealthSession::query()->where('appointment_id', $appointment->id)->firstOrFail();
        });

        $this->expectException(TenantMismatch::class);
        app(OpenSession::class)($foreign, $this->actor);
    }

    // ── consent and recording ────────────────────────────────────────────────

    #[Test]
    public function recording_is_off_by_default_and_needs_the_organizations_setting_and_recorded_consent(): void
    {
        $session = $this->sessionAt('2026-10-06 10:00:00');

        $this->assertDomainRefusal('recording_disabled', fn () => app(RecordConsent::class)($session, true, $this->actor));

        $this->enableRecording();
        $this->assertDomainRefusal('consent_required', fn () => app(AttachRecording::class)($session, $this->wav(), 60, $this->actor));

        app(RecordConsent::class)($session, true, $this->actor);
        $recording = app(AttachRecording::class)($session->refresh(), $this->wav(), 60, $this->actor);

        $this->assertTrue($session->consent_to_record);
        $this->assertNotNull($session->consent_recorded_at);
        $this->assertSame('audio/x-wav', $recording->mime);
        $this->assertSame(hash('sha256', file_get_contents(Storage::disk('local')->path($recording->storage_path))), $recording->sha256);
        $this->assertStringStartsWith("telehealth/{$session->organization_id}/{$session->id}/", $recording->storage_path);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'telehealth.recording_attached')->count());
    }

    #[Test]
    public function the_database_refuses_a_recording_or_transcript_row_for_a_session_without_consent(): void
    {
        $session = $this->sessionAt('2026-10-06 10:00:00');

        foreach (['session_recordings' => ['storage_path' => 'x', 'mime' => 'audio/x-wav', 'size_bytes' => 5, 'sha256' => str_repeat('a', 64)],
            'session_transcripts' => ['source' => 'provider', 'status' => 'draft', 'body' => 'hello']] as $table => $columns) {
            try {
                DB::transaction(fn () => DB::table($table)->insert([
                    'id' => (string) Str::uuid7(), 'organization_id' => $session->organization_id, 'record_environment' => 'live',
                    'telehealth_session_id' => $session->id, 'consented' => true, 'created_at' => now(), 'updated_at' => now(),
                ] + $columns));
                $this->fail("{$table}: a row was stored for a session without consent.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('violates foreign key constraint', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_non_media_file_is_refused_as_a_recording(): void
    {
        $session = $this->consented($this->sessionAt('2026-10-06 10:00:00'));

        $this->assertDomainRefusal('recording_type', fn () => app(AttachRecording::class)($session, UploadedFile::fake()->createWithContent('x.wav', '<?php echo 1;'), null, $this->actor));
        $this->assertSame(0, SessionRecording::query()->count());
    }

    #[Test]
    public function consent_cannot_be_withdrawn_once_a_recording_is_stored_and_withdrawing_otherwise_works(): void
    {
        $session = $this->sessionAt('2026-10-06 10:00:00');
        $this->consented($session);
        app(RecordConsent::class)($session, false, $this->actor);
        $this->assertFalse($session->refresh()->consent_to_record);

        $this->recordingFor($session);
        $this->assertDomainRefusal('consent_in_use', fn () => app(RecordConsent::class)($session->refresh(), false, $this->actor));
    }

    // ── transcripts ──────────────────────────────────────────────────────────

    #[Test]
    public function a_transcript_is_always_a_draft_until_a_clinician_reviews_it_and_ai_needs_the_opt_in(): void
    {
        $session = $this->consented($this->sessionAt('2026-10-06 10:00:00'));

        $this->assertDomainRefusal('ai_disabled', fn () => app(AddTranscript::class)($session, 'Hello', TranscriptSource::Ai, null, $this->actor));

        $this->setting('telehealth.ai_transcripts_enabled', true);
        $transcript = app(AddTranscript::class)($session, "Clinician: Hello\nClient: Hi", TranscriptSource::Ai, null, $this->actor);

        $this->assertSame(TranscriptStatus::Draft, $transcript->status);
        $this->assertSame('AI Transcript (Draft)', $transcript->label());
        $this->assertNull($transcript->reviewed_by_user_id);

        app(ReviewTranscript::class)($transcript, $this->actor);
        $transcript->refresh();
        $this->assertSame(TranscriptStatus::Reviewed, $transcript->status);
        $this->assertSame($this->actor->id, $transcript->reviewed_by_user_id);
        $this->assertNotNull($transcript->reviewed_at);
        $this->assertSame('AI Transcript', $transcript->label());

        $this->assertDomainRefusal('transcript_reviewed', fn () => app(ReviewTranscript::class)($transcript, $this->actor));

        // The audit trail names the transcript and its states, never its text.
        $this->assertStringNotContainsString('Hello', json_encode(DB::table('audit_logs')->whereIn('action', ['telehealth.transcript_added', 'telehealth.transcript_reviewed'])->get()));
    }

    #[Test]
    public function the_database_refuses_a_reviewed_transcript_without_a_reviewer(): void
    {
        $session = $this->consented($this->sessionAt('2026-10-06 10:00:00'));
        $this->setting('telehealth.ai_transcripts_enabled', true);
        $transcript = app(AddTranscript::class)($session, 'Hello', TranscriptSource::Provider, null, $this->actor);

        try {
            DB::transaction(fn () => DB::table('session_transcripts')->where('id', $transcript->id)->update(['status' => 'reviewed']));
            $this->fail('A transcript was finalised with nobody reviewing it.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('session_transcripts_reviewed_check', $e->getMessage());
        }
    }

    // ── notes ────────────────────────────────────────────────────────────────

    #[Test]
    public function an_edit_is_a_new_version_unchanged_text_adds_none_and_the_text_never_enters_the_audit_log(): void
    {
        $session = $this->sessionAt('2026-10-06 10:00:00');

        $v1 = app(SaveSessionNotes::class)($session, 'Client discussed stressors.', $this->actor);
        $this->assertSame(1, $v1->version);
        $this->assertNull(app(SaveSessionNotes::class)($session, "Client discussed stressors.\n", $this->actor), 'trailing whitespace is not a change');
        $v2 = app(SaveSessionNotes::class)($session, 'Client discussed stressors and sleep.', $this->actor);
        $this->assertSame(2, $v2->version);

        $note = $session->note()->firstOrFail();
        $this->assertSame(2, $note->latest_version);
        $this->assertSame(['Client discussed stressors.', 'Client discussed stressors and sleep.'],
            SessionNoteVersion::query()->where('session_note_id', $note->id)->orderBy('version')->get()->map(fn ($v) => $v->getAttributeValue('body'))->all());

        $entries = DB::table('audit_logs')->whereIn('action', ['telehealth.notes_created', 'telehealth.notes_updated'])->get();
        $this->assertCount(2, $entries);
        $this->assertStringNotContainsString('stressors', json_encode($entries));
        $this->assertStringNotContainsString('sleep', json_encode($entries));

        foreach ([fn () => DB::table('session_note_versions')->update(['body' => 'edited']), fn () => DB::table('session_note_versions')->delete()] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('A note version was changed.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('insert-only', $e->getMessage());
            }
        }
    }

    #[Test]
    public function notes_are_validated_and_a_session_that_did_not_happen_has_none(): void
    {
        $session = $this->sessionAt('2026-10-06 10:00:00');

        $this->assertDomainRefusal('notes_empty', fn () => app(SaveSessionNotes::class)($session, "  \n ", $this->actor));
        $this->assertDomainRefusal('notes_too_long', fn () => app(SaveSessionNotes::class)($session, str_repeat('a', SaveSessionNotes::MAX_LENGTH + 1), $this->actor));

        app(TransitionAppointment::class)(Appointment::query()->findOrFail($session->appointment_id), AppointmentStatus::Cancelled, $this->actor, null, CancellationKind::Practice);
        $this->assertDomainRefusal('session_closed', fn () => app(SaveSessionNotes::class)($session->refresh(), 'text', $this->actor));
    }

    private function assertDomainRefusal(string $code, \Closure $callback): void
    {
        try {
            $callback();
            $this->fail("Expected a refusal with code {$code}.");
        } catch (DomainException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }
}
