<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // CREATE INDEX CONCURRENTLY cannot run inside a transaction: the appointments table may be large and busy,
    // so the index is built without blocking writes.
    public $withinTransaction = false;

    public function up(): void
    {
        // Lets telehealth rows reference an appointment AND its live/demo environment in one composite key, so a
        // demo session can never point at a live appointment (the same shape clients offer to appointments).
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS appointments_org_id_env_unique ON appointments (organization_id, id, record_environment)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS appointments_org_id_env_unique');
    }
};
