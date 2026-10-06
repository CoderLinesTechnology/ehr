<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // CREATE INDEX CONCURRENTLY cannot run inside a transaction: appointments may be large and busy.
    public $withinTransaction = false;

    public function up(): void
    {
        // Target of the tasks module's "the appointment belongs to the task's client" foreign key
        // (tasks (organization_id, appointment_id, client_id) → appointments): the database, not only the action,
        // refuses a task that names one client and another client's appointment. Same shape as the environment key.
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS appointments_org_id_client_unique ON appointments (organization_id, id, client_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS appointments_org_id_client_unique');
    }
};
