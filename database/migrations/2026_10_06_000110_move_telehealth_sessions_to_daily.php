<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Daily replaces the "external meeting link" provider. Sessions of that provider move to Daily and lose their
     * pasted Zoom/Meet/Teams link (a Daily room is created when someone next opens the join page); the two settings
     * that only served links (the default link and the host allowlist) are removed. Idempotent: a second run
     * changes nothing.
     */
    public function up(): void
    {
        DB::table('telehealth_sessions')->where('provider_key', 'external_link')
            ->update(['provider_key' => 'daily', 'join_url' => null, 'updated_at' => now()]);

        DB::table('organization_settings')
            ->whereIn('key', ['telehealth.default_link_secret', 'telehealth.allowed_hosts'])
            ->delete();
    }

    public function down(): void
    {
        // Nothing to restore: the pasted links and the two settings are gone on purpose.
    }
};
