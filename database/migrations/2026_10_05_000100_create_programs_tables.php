<?php

use App\Support\Database\Schema\TenantBlueprint as T;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Programs and levels of care. Tenant rules: organization_id on every row, composite tenant foreign keys,
 * enrollments carry the client's record environment (FK-enforced), history is insert-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('icon', 24)->default('users-round');
            $table->string('color', 8)->default('blue');
            $table->string('status', 12)->default('upcoming');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->uuid('location_id')->nullable();
            $table->boolean('is_online')->default(false);
            // 42 CFR Part 2: enrollments in a flagged program are visible only with programs.view_sud.
            $table->boolean('is_sud_program')->default(false);
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->tenantKey();
            $table->tenantForeign('location_id', 'locations');
            $table->index(['organization_id', 'status', 'starts_on']);
        });
        T::checkIn('programs', 'status', ['upcoming', 'active', 'on_hold', 'completed', 'archived']);
        T::checkIn('programs', 'color', ['blue', 'green', 'purple', 'orange', 'red', 'teal']);
        T::check('programs', 'dates', 'ends_on IS NULL OR ends_on >= starts_on');
        T::check('programs', 'place', 'NOT (is_online AND location_id IS NOT NULL)');
        T::check('programs', 'description_length', 'description IS NULL OR char_length(description) <= 1000');
        DB::statement('CREATE UNIQUE INDEX programs_name_unique ON programs (organization_id, lower(name))');

        Schema::create('levels_of_care', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->uuid('program_id');
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->text('eligibility')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->tenantKey();
            // Target of the enrollment's "level belongs to the same program" foreign key.
            $table->unique(['organization_id', 'id', 'program_id']);
            $table->tenantForeign('program_id', 'programs');
            $table->index(['organization_id', 'program_id', 'sort']);
        });
        T::check('levels_of_care', 'text_length', '(description IS NULL OR char_length(description) <= 1000) AND (eligibility IS NULL OR char_length(eligibility) <= 1000)');
        DB::statement('CREATE UNIQUE INDEX levels_of_care_name_unique ON levels_of_care (organization_id, program_id, lower(name))');

        Schema::create('program_staff', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->uuid('program_id');
            $table->uuid('membership_id');
            $table->string('role', 12);
            $table->timestampsTz();

            $table->tenantKey();
            $table->tenantForeign('program_id', 'programs');
            $table->tenantForeign('membership_id', 'organization_memberships');
            $table->unique(['organization_id', 'program_id', 'membership_id']);
            $table->index(['organization_id', 'membership_id']);
        });
        T::checkIn('program_staff', 'role', ['director', 'clinician', 'supervisor', 'coordinator', 'other']);

        Schema::create('program_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->recordEnvironment();
            $table->uuid('client_id');
            $table->uuid('program_id');
            $table->uuid('current_level_id')->nullable();
            $table->string('status', 12)->default('active');
            $table->timestampTz('admitted_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();

            $table->tenantKey();
            $table->unique(['organization_id', 'id', 'record_environment']);
            $table->unique(['organization_id', 'id', 'program_id']);
            $table->foreign(['organization_id', 'client_id', 'record_environment'])
                ->references(['organization_id', 'id', 'record_environment'])->on('clients')->restrictOnDelete();
            $table->tenantForeign('program_id', 'programs');
            $table->foreign(['organization_id', 'current_level_id', 'program_id'])
                ->references(['organization_id', 'id', 'program_id'])->on('levels_of_care')->restrictOnDelete();
            $table->index(['organization_id', 'program_id', 'status']);
            $table->index(['organization_id', 'client_id']);
        });
        T::checkIn('program_enrollments', 'record_environment', ['live', 'demo']);
        T::checkIn('program_enrollments', 'status', ['pending', 'active', 'on_hold', 'completed', 'discharged', 'transferred']);
        T::check('program_enrollments', 'ended', "(status IN ('pending', 'active', 'on_hold')) = (ended_at IS NULL)");
        // At most one OPEN enrollment per client per program, whatever code path writes it.
        DB::statement("CREATE UNIQUE INDEX program_enrollments_one_open ON program_enrollments (organization_id, program_id, client_id) WHERE status IN ('pending', 'active', 'on_hold')");

        Schema::create('program_enrollment_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->recordEnvironment();
            $table->uuid('enrollment_id');
            $table->string('event_type', 16);
            $table->string('from_status', 12)->nullable();
            $table->string('to_status', 12);
            $table->uuid('from_level_id')->nullable();
            $table->uuid('to_level_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->uuid('authorized_by_membership_id')->nullable();
            $table->uuid('related_enrollment_id')->nullable();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Microseconds: the history is ordered by this column.
            $table->timestampTz('occurred_at', 6)->useCurrent();

            $table->foreign(['organization_id', 'enrollment_id', 'record_environment'])
                ->references(['organization_id', 'id', 'record_environment'])->on('program_enrollments')->restrictOnDelete();
            $table->tenantForeign('from_level_id', 'levels_of_care');
            $table->tenantForeign('to_level_id', 'levels_of_care');
            $table->tenantForeign('authorized_by_membership_id', 'organization_memberships');
            $table->tenantForeign('related_enrollment_id', 'program_enrollments');
            $table->index(['organization_id', 'enrollment_id', 'occurred_at']);
        });
        // History order is the insertion order, whatever the clock said (two events can share a microsecond).
        DB::statement('ALTER TABLE program_enrollment_events ADD COLUMN seq bigint GENERATED ALWAYS AS IDENTITY');
        DB::statement('CREATE UNIQUE INDEX program_enrollment_events_seq ON program_enrollment_events (organization_id, enrollment_id, seq)');
        T::checkIn('program_enrollment_events', 'record_environment', ['live', 'demo']);
        T::checkIn('program_enrollment_events', 'event_type', ['admitted', 'level_changed', 'put_on_hold', 'resumed', 'discharged', 'completed', 'transferred']);
        T::insertOnly('program_enrollment_events', allowDemoPurge: true);

        Schema::create('program_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->uuid('program_id');
            $table->string('title', 120);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('timezone', 64);
            $table->uuid('location_id')->nullable();
            $table->boolean('is_online')->default(false);
            $table->uuid('facilitator_membership_id')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->tenantKey();
            $table->unique(['organization_id', 'id', 'program_id']);
            $table->tenantForeign('program_id', 'programs');
            $table->tenantForeign('location_id', 'locations');
            $table->tenantForeign('facilitator_membership_id', 'organization_memberships');
            $table->index(['organization_id', 'starts_at']);
            $table->index(['organization_id', 'program_id', 'starts_at']);
        });
        T::check('program_sessions', 'range', 'ends_at > starts_at');
        T::check('program_sessions', 'place', 'NOT (is_online AND location_id IS NOT NULL)');

        Schema::create('program_session_attendance', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->recordEnvironment();
            $table->uuid('program_id');
            $table->uuid('session_id');
            $table->uuid('enrollment_id');
            $table->string('status', 8);
            $table->foreignUuid('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->tenantKey();
            // The session and the enrollment are of the same program and the same environment: the database says so.
            $table->foreign(['organization_id', 'session_id', 'program_id'])
                ->references(['organization_id', 'id', 'program_id'])->on('program_sessions')->restrictOnDelete();
            $table->foreign(['organization_id', 'enrollment_id', 'program_id'])
                ->references(['organization_id', 'id', 'program_id'])->on('program_enrollments')->restrictOnDelete();
            $table->foreign(['organization_id', 'enrollment_id', 'record_environment'])
                ->references(['organization_id', 'id', 'record_environment'])->on('program_enrollments')->restrictOnDelete();
            $table->unique(['organization_id', 'session_id', 'enrollment_id']);
            $table->index(['organization_id', 'enrollment_id']);
        });
        T::checkIn('program_session_attendance', 'record_environment', ['live', 'demo']);
        T::checkIn('program_session_attendance', 'status', ['present', 'absent', 'excused']);
    }

    public function down(): void
    {
        Schema::dropIfExists('program_session_attendance');
        Schema::dropIfExists('program_sessions');
        Schema::dropIfExists('program_enrollment_events');
        Schema::dropIfExists('program_enrollments');
        Schema::dropIfExists('program_staff');
        Schema::dropIfExists('levels_of_care');
        Schema::dropIfExists('programs');
    }
};
