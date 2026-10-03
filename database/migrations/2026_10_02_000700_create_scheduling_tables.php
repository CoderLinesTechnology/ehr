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
        // Recurring wall-clock windows in the location's timezone (organization
        // timezone when there is no location, i.e. telehealth-only).
        Schema::create('availability_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->uuid('membership_id');
            $table->uuid('location_id')->nullable();
            $table->smallInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('modality', 16);
            $table->smallInteger('repeat_every_weeks')->default(1);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('is_bookable_online')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->tenantKey();
            $table->tenantForeign('membership_id', 'organization_memberships');
            $table->tenantForeign('location_id', 'locations');
            $table->index(['organization_id', 'membership_id', 'weekday']);
        });
        T::checkIn('availability_rules', 'modality', ['in_person', 'telehealth', 'any']);
        T::check('availability_rules', 'weekday', 'weekday BETWEEN 1 AND 7');
        T::check('availability_rules', 'window', 'end_time > start_time');
        T::check('availability_rules', 'repeat', 'repeat_every_weeks BETWEEN 1 AND 8');
        T::check('availability_rules', 'effective', 'effective_until IS NULL OR effective_until >= effective_from');
        T::check('availability_rules', 'location', "modality = 'telehealth' OR location_id IS NOT NULL");

        // Empty = every service the clinician provides.
        Schema::create('availability_rule_services', function (Blueprint $table) {
            $table->tenant();
            $table->uuid('availability_rule_id');
            $table->uuid('service_id');

            $table->primary(['availability_rule_id', 'service_id']);
            $table->tenantForeign('availability_rule_id', 'availability_rules', 'cascade');
            $table->tenantForeign('service_id', 'services', 'cascade');
        });

        Schema::create('blocked_times', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            // NULL membership = applies to everyone (holiday, closure).
            $table->uuid('membership_id')->nullable();
            $table->uuid('location_id')->nullable();
            $table->string('kind', 16);
            $table->string('title', 120)->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->boolean('all_day')->default(false);
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->tenantForeign('membership_id', 'organization_memberships');
            $table->tenantForeign('location_id', 'locations');
        });
        T::checkIn('blocked_times', 'kind', ['blocked', 'leave', 'holiday', 'unavailable']);
        T::check('blocked_times', 'range', 'ends_at > starts_at');
        DB::statement('CREATE INDEX blocked_times_org_range ON blocked_times USING gist (organization_id, tstzrange(starts_at, ends_at))');

        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->recordEnvironment();
            $table->uuid('client_id');
            $table->uuid('service_id');
            $table->uuid('clinician_membership_id');
            $table->uuid('location_id')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('timezone', 64);
            $table->string('modality', 16);
            $table->string('status', 16);
            $table->boolean('allow_overlap')->default(false);
            $table->string('source', 16)->default('staff');
            // Price snapshot at booking; billing charges from this, never from the live service.
            $table->bigInteger('price_minor');
            $table->char('currency', 3);
            $table->text('scheduling_notes')->nullable();
            $table->string('cancellation_kind', 16)->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->boolean('late_cancellation')->default(false);
            $table->uuid('rescheduled_from_id')->nullable();
            $table->foreignUuid('booked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('checked_in_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('no_show_at')->nullable();
            $table->timestampsTz();

            $table->tenantKey();
            $table->foreign(['organization_id', 'client_id', 'record_environment'])
                ->references(['organization_id', 'id', 'record_environment'])->on('clients')
                ->restrictOnDelete();
            $table->tenantForeign('service_id', 'services');
            $table->tenantForeign('clinician_membership_id', 'organization_memberships');
            $table->tenantForeign('location_id', 'locations');
            $table->tenantForeign('rescheduled_from_id', 'appointments');
            $table->index(['organization_id', 'starts_at']);
            $table->index(['organization_id', 'client_id', 'starts_at']);
            $table->index(['organization_id', 'rescheduled_from_id']);
        });
        T::checkIn('appointments', 'record_environment', ['live', 'demo']);
        T::checkIn('appointments', 'modality', ['in_person', 'telehealth']);
        T::checkIn('appointments', 'status', ['scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show', 'rescheduled']);
        T::checkIn('appointments', 'source', ['staff', 'portal', 'public_booking', 'waitlist']);
        T::check('appointments', 'range', 'ends_at > starts_at');
        T::check('appointments', 'price', 'price_minor >= 0');
        T::check('appointments', 'cancellation', "cancellation_kind IS NULL OR cancellation_kind IN ('client', 'practice')");
        T::check('appointments', 'in_person_location', "modality = 'telehealth' OR location_id IS NOT NULL");
        // The database, not the application, guarantees a clinician is never
        // double-booked: concurrent bookings of one slot cannot both commit.
        DB::statement(<<<'SQL'
            ALTER TABLE appointments ADD CONSTRAINT appointments_no_clinician_overlap
            EXCLUDE USING gist (
                organization_id WITH =,
                clinician_membership_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
            ) WHERE (NOT allow_overlap AND status IN ('scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed'))
        SQL);

        Schema::create('appointment_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->uuid('appointment_id');
            $table->recordEnvironment();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->string('reason', 500)->nullable();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->tenantForeign('appointment_id', 'appointments', 'cascade');
            $table->index(['organization_id', 'appointment_id', 'occurred_at']);
        });
        T::insertOnly('appointment_status_histories', allowDemoPurge: true);
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_status_histories');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('blocked_times');
        Schema::dropIfExists('availability_rule_services');
        Schema::dropIfExists('availability_rules');
    }
};
