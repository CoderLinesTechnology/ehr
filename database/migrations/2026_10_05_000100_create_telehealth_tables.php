<?php

use App\Support\Database\Schema\TenantBlueprint as T;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The appointments (organization, id, record_environment) key these FKs rely on is built concurrently by the
        // migration before this one (2026_10_05_000090_index_appointments_environment_key).

        // One video session per telehealth appointment. The meeting link is encrypted at rest and never written to audit.
        Schema::create('telehealth_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->recordEnvironment();
            $table->uuid('appointment_id');
            $table->uuid('client_id');
            // Copied from the appointment so the list can filter "my sessions" without a join.
            $table->uuid('clinician_membership_id');
            // Copied from the appointment when the session is created (an appointment is never re-timed in place:
            // rescheduling creates a replacement, and with it a new session), so lists order and filter on this
            // table's own indexes instead of joining appointments first.
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 16)->default('scheduled');
            $table->string('provider_key', 32);
            $table->text('join_url')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            // Recording is off unless consent was recorded; recordings and transcripts reference this column
            // through a composite foreign key, so the database refuses a recording of a session without consent.
            $table->boolean('consent_to_record')->default(false);
            $table->foreignUuid('consent_recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('consent_recorded_at')->nullable();
            $table->timestampsTz();

            $table->tenantKey();
            $table->unique(['organization_id', 'id', 'consent_to_record'], 'telehealth_sessions_consent_key');
            $table->unique(['organization_id', 'appointment_id']);
            $table->foreign(['organization_id', 'appointment_id', 'record_environment'], 'telehealth_sessions_appointment_fk')
                ->references(['organization_id', 'id', 'record_environment'])->on('appointments')->restrictOnDelete();
            $table->foreign(['organization_id', 'client_id', 'record_environment'], 'telehealth_sessions_client_fk')
                ->references(['organization_id', 'id', 'record_environment'])->on('clients')->restrictOnDelete();
            $table->tenantForeign('clinician_membership_id', 'organization_memberships');
            $table->index(['organization_id', 'starts_at']);
            $table->index(['organization_id', 'clinician_membership_id', 'starts_at']);
        });
        T::check('telehealth_sessions', 'range', "ends_at > starts_at AND ends_at - starts_at <= interval '24 hours'");
        T::checkIn('telehealth_sessions', 'status', ['scheduled', 'waiting', 'in_progress', 'completed', 'cancelled', 'missed']);
        T::checkIn('telehealth_sessions', 'record_environment', ['live', 'demo']);
        T::check('telehealth_sessions', 'consent', 'NOT consent_to_record OR consent_recorded_at IS NOT NULL');
        T::check('telehealth_sessions', 'ended', 'ended_at IS NULL OR started_at IS NULL OR ended_at >= started_at');
        T::check('telehealth_sessions', 'join_url_length', 'join_url IS NULL OR char_length(join_url) <= 4000');

        Schema::create('telehealth_session_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->recordEnvironment();
            $table->uuid('telehealth_session_id');
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->string('reason', 200)->nullable();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->tenantForeign('telehealth_session_id', 'telehealth_sessions', 'cascade');
            $table->index(['organization_id', 'telehealth_session_id', 'occurred_at'], 'telehealth_status_histories_session_index');
        });
        T::insertOnly('telehealth_session_status_histories', allowDemoPurge: true);

        // Recording files live on the private disk; only metadata is stored here.
        Schema::create('session_recordings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->recordEnvironment();
            $table->uuid('telehealth_session_id');
            $table->boolean('consented')->default(true);
            $table->string('storage_path', 200);
            $table->string('mime', 40);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->char('sha256', 64);
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('purged_at')->nullable();
            $table->timestampsTz();

            $table->tenantKey();
            $table->foreign(['organization_id', 'telehealth_session_id', 'consented'], 'session_recordings_consent_fk')
                ->references(['organization_id', 'id', 'consent_to_record'])->on('telehealth_sessions')->restrictOnDelete();
            $table->index(['organization_id', 'telehealth_session_id']);
        });
        T::check('session_recordings', 'consented', 'consented');
        T::check('session_recordings', 'size', 'size_bytes > 0');

        Schema::create('session_transcripts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->recordEnvironment();
            $table->uuid('telehealth_session_id');
            $table->boolean('consented')->default(true);
            $table->uuid('session_recording_id')->nullable();
            // provider = the video vendor's own transcript; ai = machine generated. Both are drafts until a clinician reviews.
            $table->string('source', 12);
            $table->string('status', 12)->default('draft');
            $table->text('body');
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->tenantKey();
            $table->foreign(['organization_id', 'telehealth_session_id', 'consented'], 'session_transcripts_consent_fk')
                ->references(['organization_id', 'id', 'consent_to_record'])->on('telehealth_sessions')->restrictOnDelete();
            $table->tenantForeign('session_recording_id', 'session_recordings');
            $table->index(['organization_id', 'telehealth_session_id']);
        });
        T::checkIn('session_transcripts', 'source', ['provider', 'ai']);
        T::checkIn('session_transcripts', 'status', ['draft', 'reviewed']);
        T::check('session_transcripts', 'consented', 'consented');
        T::check('session_transcripts', 'reviewed', "status = 'draft' OR (reviewed_by_user_id IS NOT NULL AND reviewed_at IS NOT NULL)");
        T::check('session_transcripts', 'body_length', 'char_length(body) <= 500000');

        // The clinician's session summary. Self-contained so a later clinical-notes module can adopt the rows:
        // the header gives a note a stable identity, every edit is a new insert-only version.
        Schema::create('session_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->recordEnvironment();
            $table->uuid('telehealth_session_id');
            $table->unsignedInteger('latest_version')->default(0);
            $table->timestampsTz();

            $table->tenantKey();
            $table->unique(['organization_id', 'telehealth_session_id']);
            $table->tenantForeign('telehealth_session_id', 'telehealth_sessions');
        });

        Schema::create('session_note_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->recordEnvironment();
            $table->uuid('session_note_id');
            $table->unsignedInteger('version');
            $table->text('body');
            $table->foreignUuid('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['session_note_id', 'version']);
            $table->tenantForeign('session_note_id', 'session_notes', 'cascade');
        });
        T::check('session_note_versions', 'body_length', 'char_length(body) <= 20000');
        T::insertOnly('session_note_versions', allowDemoPurge: true);

        // Telehealth appointments booked before this module existed get their session now (status mapped, no link, no
        // consent), so the list is complete from the first day. Later bookings are created by the scheduling listener.
        DB::statement(<<<'SQL'
            INSERT INTO telehealth_sessions (id, organization_id, record_environment, appointment_id, client_id, clinician_membership_id,
                starts_at, ends_at, status, provider_key, started_at, ended_at, duration_minutes, consent_to_record, created_at, updated_at)
            SELECT gen_random_uuid(), a.organization_id, a.record_environment, a.id, a.client_id, a.clinician_membership_id,
                a.starts_at, a.ends_at,
                CASE a.status WHEN 'in_progress' THEN 'in_progress' WHEN 'completed' THEN 'completed'
                    WHEN 'cancelled' THEN 'cancelled' WHEN 'rescheduled' THEN 'cancelled' WHEN 'no_show' THEN 'missed' ELSE 'scheduled' END,
                'external_link', a.started_at,
                CASE WHEN a.status = 'completed' AND (a.started_at IS NULL OR a.completed_at >= a.started_at) THEN a.completed_at END,
                CASE WHEN a.status = 'completed' THEN (EXTRACT(EPOCH FROM (a.ends_at - a.starts_at)) / 60)::int END,
                false, now(), now()
            FROM appointments a WHERE a.modality = 'telehealth'
            ON CONFLICT (organization_id, appointment_id) DO NOTHING
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO telehealth_session_status_histories (id, organization_id, record_environment, telehealth_session_id, from_status, to_status, reason, occurred_at)
            SELECT gen_random_uuid(), s.organization_id, s.record_environment, s.id, NULL, s.status, 'Created with the telehealth module', now()
            FROM telehealth_sessions s
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('session_note_versions');
        Schema::dropIfExists('session_notes');
        Schema::dropIfExists('session_transcripts');
        Schema::dropIfExists('session_recordings');
        Schema::dropIfExists('telehealth_session_status_histories');
        Schema::dropIfExists('telehealth_sessions');
    }
};
