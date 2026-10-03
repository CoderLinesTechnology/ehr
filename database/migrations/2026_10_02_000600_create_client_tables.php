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
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->recordEnvironment();
            $table->integer('client_number');
            $table->string('status', 16)->default('active');
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('preferred_name', 100)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('sex', 16)->nullable();
            $table->string('gender_identity', 60)->nullable();
            $table->string('pronouns', 40)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('preferred_contact_method', 16)->nullable();
            $table->string('address_line1', 200)->nullable();
            $table->string('address_line2', 200)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->uuid('primary_clinician_membership_id')->nullable();
            $table->uuid('primary_location_id')->nullable();
            $table->string('referral_source', 120)->nullable();
            $table->text('administrative_notes')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->tenantKey();
            // Target for child FKs that must also agree on live/demo.
            $table->unique(['organization_id', 'id', 'record_environment']);
            $table->unique(['organization_id', 'client_number']);
            $table->index(['organization_id', 'status', 'last_name', 'first_name']);
            $table->index(['organization_id', 'primary_clinician_membership_id']);
            $table->tenantForeign('primary_clinician_membership_id', 'organization_memberships');
            $table->tenantForeign('primary_location_id', 'locations');
        });
        T::checkIn('clients', 'record_environment', ['live', 'demo']);
        T::checkIn('clients', 'status', ['active', 'inactive', 'archived']);
        T::check('clients', 'sex', "sex IS NULL OR sex IN ('female', 'male', 'intersex', 'unknown', 'undisclosed')");
        T::check('clients', 'contact_method', "preferred_contact_method IS NULL OR preferred_contact_method IN ('email', 'phone', 'sms')");
        // Staff search by name, email, phone or number; trigram GIN serves ILIKE '%term%'.
        DB::statement(<<<'SQL'
            ALTER TABLE clients ADD COLUMN search_text text GENERATED ALWAYS AS (
                lower(
                    first_name || ' ' || coalesce(preferred_name, '') || ' ' || last_name || ' '
                    || coalesce(email, '') || ' ' || coalesce(phone, '')
                )
            ) STORED
        SQL);
        DB::statement('CREATE INDEX clients_search_text_trgm ON clients USING gin (search_text gin_trgm_ops)');

        Schema::create('client_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->uuid('client_id');
            $table->string('name', 150);
            $table->string('relationship', 60)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 254)->nullable();
            $table->boolean('is_emergency_contact')->default(false);
            $table->string('notes', 500)->nullable();
            $table->smallInteger('sort')->default(0);
            $table->timestampsTz();

            $table->tenantForeign('client_id', 'clients', 'cascade');
            $table->index(['organization_id', 'client_id']);
        });

        // Projection written by event listeners; read with keyset pagination.
        Schema::create('timeline_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->uuid('client_id');
            $table->recordEnvironment();
            $table->timestampTz('occurred_at');
            $table->string('category', 20);
            $table->string('type', 60);
            $table->string('subject_type', 60)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('summary', 300);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['organization_id', 'client_id', 'record_environment'])
                ->references(['organization_id', 'id', 'record_environment'])->on('clients')
                ->cascadeOnDelete();
            $table->index(['organization_id', 'client_id', 'occurred_at', 'id']);
        });
        T::checkIn('timeline_entries', 'category', ['administrative', 'scheduling', 'clinical', 'financial', 'communication', 'program', 'document']);
        T::insertOnly('timeline_entries', allowDemoPurge: true);
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_entries');
        Schema::dropIfExists('client_contacts');
        Schema::dropIfExists('clients');
    }
};
