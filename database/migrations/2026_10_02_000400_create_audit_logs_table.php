<?php

use App\Support\Database\Schema\TenantBlueprint as T;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Insert-only and deliberately free of foreign keys: an audit trail must
        // outlive anything it describes and must never block another write.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->string('context', 16);
            $table->string('action', 100);
            $table->uuid('actor_user_id')->nullable();
            $table->string('actor_label', 200)->nullable();
            $table->uuid('organization_id')->nullable();
            $table->string('subject_type', 60)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('summary', 300)->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->uuid('request_id')->nullable();

            $table->index(['organization_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['context', 'occurred_at']);
        });
        T::checkIn('audit_logs', 'context', ['platform', 'organization', 'portal', 'public', 'system']);
        T::insertOnly('audit_logs');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
