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
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 63)->unique();
            $table->string('name', 160);
            $table->string('legal_name', 200)->nullable();
            $table->string('status', 16);
            $table->string('status_reason', 500)->nullable();
            $table->timestampTz('status_changed_at')->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('address_line1', 200)->nullable();
            $table->string('address_line2', 200)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->char('country_code', 2);
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->string('locale', 12)->default('en');
            $table->string('logo_path', 255)->nullable();
            $table->string('custom_domain', 253)->nullable()->unique();
            $table->timestampTz('onboarding_completed_at')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
        T::checkIn('organizations', 'status', ['pending', 'trial', 'active', 'suspended', 'archived', 'cancelled']);
        // Subdomain-safe slug: the public site lives at {slug}.{platform-domain}.
        T::check('organizations', 'slug_format', "slug ~ '^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\$'");

        Schema::create('organization_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->string('reason', 500)->nullable();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index(['organization_id', 'occurred_at']);
        });
        T::insertOnly('organization_status_histories');

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 120)->primary();
            $table->jsonb('value');
            $table->foreignUuid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('organization_settings', function (Blueprint $table) {
            $table->tenant();
            $table->string('key', 120);
            $table->jsonb('value');
            $table->foreignUuid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['organization_id', 'key']);
        });

        // Per-organization human-facing sequences (client numbers, later invoice numbers).
        Schema::create('organization_counters', function (Blueprint $table) {
            $table->tenant();
            $table->string('key', 40);
            $table->bigInteger('value')->default(0);

            $table->primary(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_counters');
        Schema::dropIfExists('organization_settings');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('organization_status_histories');
        Schema::dropIfExists('organizations');
        DB::statement('DROP FUNCTION IF EXISTS forbid_row_mutation() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS forbid_row_mutation_except_demo_purge() CASCADE');
    }
};
