<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // CREATE INDEX CONCURRENTLY cannot run inside a transaction: telehealth_sessions grows with every telehealth
    // appointment, so the index is built without blocking writes.
    public $withinTransaction = false;

    public function up(): void
    {
        // One session per Daily room, across all organizations: Daily's webhooks are domain-wide and name only the
        // room, so the receiver resolves the session (and with it the tenant) through this index.
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS telehealth_sessions_provider_room_name_unique
            ON telehealth_sessions (provider_room_name) WHERE provider_room_name IS NOT NULL');

        // A Daily recording is stored once, however often its webhook is delivered.
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS session_recordings_provider_recording_id_unique
            ON session_recordings (provider_recording_id) WHERE provider_recording_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS session_recordings_provider_recording_id_unique');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS telehealth_sessions_provider_room_name_unique');
    }
};
