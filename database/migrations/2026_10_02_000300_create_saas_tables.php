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
        // Code-defined catalogue (FeatureRegistry): boolean modules and numeric limits.
        Schema::create('features', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('type', 16);
            $table->string('unit', 20)->nullable();
            $table->smallInteger('sort')->default(0);
            $table->timestampsTz();
        });
        T::checkIn('features', 'type', ['boolean', 'limit']);

        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 40)->unique();
            $table->string('name', 120);
            $table->string('description', 1000)->nullable();
            $table->bigInteger('price_minor');
            $table->char('currency', 3);
            $table->string('billing_interval', 8);
            $table->smallInteger('trial_days')->default(0);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort')->default(0);
            $table->timestampsTz();
        });
        T::checkIn('plans', 'billing_interval', ['month', 'year']);
        T::check('plans', 'price', 'price_minor >= 0');
        T::check('plans', 'trial_days', 'trial_days BETWEEN 0 AND 365');

        // Boolean features use `enabled`; limit features use `limit_value` (NULL = unlimited).
        Schema::create('plan_features', function (Blueprint $table) {
            $table->foreignUuid('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature_key', 60);
            $table->boolean('enabled')->default(false);
            $table->bigInteger('limit_value')->nullable();

            $table->primary(['plan_id', 'feature_key']);
            $table->foreign('feature_key')->references('key')->on('features')->cascadeOnDelete();
        });
        T::check('plan_features', 'limit', 'limit_value IS NULL OR limit_value >= 0');

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->foreignUuid('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('status', 16);
            // Snapshot of what was sold; editing the plan never rewrites it.
            $table->bigInteger('price_minor');
            $table->char('currency', 3);
            $table->string('billing_interval', 8);
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_starts_at')->nullable();
            $table->timestampTz('current_period_ends_at')->nullable();
            $table->timestampTz('grace_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->string('provider', 40)->default('manual');
            $table->string('provider_reference', 191)->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'created_at']);
            $table->index(['status', 'trial_ends_at']);
        });
        T::checkIn('subscriptions', 'status', ['trialing', 'active', 'past_due', 'grace', 'cancelled', 'expired']);
        T::checkIn('subscriptions', 'billing_interval', ['month', 'year']);
        DB::statement("CREATE UNIQUE INDEX subscriptions_one_live_per_org ON subscriptions (organization_id) WHERE status IN ('trialing', 'active', 'past_due', 'grace')");

        Schema::create('subscription_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->restrictOnDelete();
            $table->string('event', 40);
            $table->foreignUuid('from_plan_id')->nullable()->constrained('plans')->restrictOnDelete();
            $table->foreignUuid('to_plan_id')->nullable()->constrained('plans')->restrictOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index(['organization_id', 'occurred_at']);
        });
        T::insertOnly('subscription_histories');

        Schema::create('organization_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->string('feature_key', 60);
            $table->boolean('enabled')->nullable();
            $table->bigInteger('limit_value')->nullable();
            $table->string('reason', 500);
            $table->timestampTz('expires_at')->nullable();
            $table->foreignUuid('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_id', 'feature_key']);
            $table->foreign('feature_key')->references('key')->on('features')->cascadeOnDelete();
        });
        T::check('organization_entitlements', 'limit', 'limit_value IS NULL OR limit_value >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_entitlements');
        Schema::dropIfExists('subscription_histories');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('features');
    }
};
