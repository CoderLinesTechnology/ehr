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
        Schema::create('locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->string('name', 120);
            $table->string('address_line1', 200)->nullable();
            $table->string('address_line2', 200)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('timezone', 64);
            // Weekly opening hours: {"1": [["08:00","17:00"]], ...} keyed by ISO weekday.
            $table->jsonb('business_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort')->default(0);
            $table->timestampsTz();

            $table->tenantKey();
        });
        DB::statement('CREATE UNIQUE INDEX locations_org_name_unique ON locations (organization_id, lower(name))');

        Schema::create('services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->string('name', 120);
            $table->string('description', 2000)->nullable();
            $table->string('code', 20)->nullable();
            $table->smallInteger('duration_minutes');
            $table->bigInteger('price_minor');
            $table->char('currency', 3);
            $table->boolean('allows_in_person')->default(true);
            $table->boolean('allows_telehealth')->default(false);
            $table->boolean('is_bookable_online')->default(false);
            $table->string('billing_behavior', 16)->default('billable');
            $table->boolean('requires_documentation')->default(false);
            $table->smallInteger('cancellation_notice_hours')->nullable();
            $table->bigInteger('late_cancellation_fee_minor')->nullable();
            $table->bigInteger('no_show_fee_minor')->nullable();
            $table->char('color', 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort')->default(0);
            $table->timestampsTz();

            $table->tenantKey();
        });
        DB::statement('CREATE UNIQUE INDEX services_org_name_unique ON services (organization_id, lower(name))');
        T::checkIn('services', 'billing_behavior', ['billable', 'non_billable']);
        T::check('services', 'duration', 'duration_minutes BETWEEN 5 AND 1440');
        T::check('services', 'money', 'price_minor >= 0 AND coalesce(late_cancellation_fee_minor, 0) >= 0 AND coalesce(no_show_fee_minor, 0) >= 0');
        T::check('services', 'modality', 'allows_in_person OR allows_telehealth');
        T::check('services', 'color', "color IS NULL OR color ~ '^#[0-9a-fA-F]{6}\$'");

        Schema::create('service_providers', function (Blueprint $table) {
            $table->tenant();
            $table->uuid('service_id');
            $table->uuid('membership_id');

            $table->primary(['service_id', 'membership_id']);
            $table->tenantForeign('service_id', 'services', 'cascade');
            $table->tenantForeign('membership_id', 'organization_memberships', 'cascade');
            $table->index(['organization_id', 'membership_id']);
        });

        Schema::create('service_locations', function (Blueprint $table) {
            $table->tenant();
            $table->uuid('service_id');
            $table->uuid('location_id');

            $table->primary(['service_id', 'location_id']);
            $table->tenantForeign('service_id', 'services', 'cascade');
            $table->tenantForeign('location_id', 'locations', 'cascade');
            $table->index(['organization_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_locations');
        Schema::dropIfExists('service_providers');
        Schema::dropIfExists('services');
        Schema::dropIfExists('locations');
    }
};
