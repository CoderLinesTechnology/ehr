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
        // Overlap queries look back at most 24h from a window's start
        // (Scheduling\Support\Conflicts::MAX_APPOINTMENT_MINUTES), so no
        // appointment may be longer — the services table caps duration at 1440.
        T::check('appointments', 'max_duration', "ends_at - starts_at <= interval '24 hours'");

        // Clinician conflict checks and per-clinician calendars filter on the
        // clinician before the time range.
        Schema::table('appointments', function (Blueprint $table) {
            $table->index(['organization_id', 'clinician_membership_id', 'starts_at'], 'appointments_org_clinician_starts_index');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_org_clinician_starts_index');
        });
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_max_duration_check');
    }
};
