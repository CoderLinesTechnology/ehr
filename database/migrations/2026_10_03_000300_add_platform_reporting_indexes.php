<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Indexes for the platform console's read models (counts and newest-first
     * lists across tenants), which no tenant-leading index serves.
     */
    public function up(): void
    {
        // AuditLog::platformVisible() newest first; grows with every sign-in.
        DB::statement("CREATE INDEX audit_logs_platform_visible_recent ON audit_logs (occurred_at DESC, id DESC) WHERE context IN ('platform', 'system')");
        // Dashboard: live active clients per organization (count only).
        DB::statement("CREATE INDEX clients_live_active ON clients (organization_id) WHERE record_environment = 'live' AND status = 'active'");
        DB::statement('CREATE INDEX appointments_starts_at ON appointments (starts_at)');
        DB::statement('CREATE INDEX users_created_at ON users (created_at)');
        DB::statement('CREATE INDEX users_last_login_at ON users (last_login_at)');
    }

    public function down(): void
    {
        foreach (['audit_logs_platform_visible_recent', 'clients_live_active', 'appointments_starts_at', 'users_created_at', 'users_last_login_at'] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};
