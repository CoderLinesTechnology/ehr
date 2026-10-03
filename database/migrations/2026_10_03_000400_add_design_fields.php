<?php

use App\Support\Database\Schema\TenantBlueprint as T;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Fields the WellNest screens display (docs/design/comps). */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('tagline', 120)->nullable()->after('legal_name');      // "Mental Health & Wellness"
            $table->string('description', 500)->nullable()->after('tagline');    // Settings → General Information
        });

        Schema::table('organization_memberships', function (Blueprint $table) {
            $table->string('name_prefix', 20)->nullable()->after('status');      // "Dr." on schedules
        });

        // Clients can be registered before intake completes ("Pending").
        DB::statement('ALTER TABLE clients DROP CONSTRAINT clients_status_check');
        T::checkIn('clients', 'status', ['pending', 'active', 'inactive', 'archived']);
    }

    public function down(): void
    {
        DB::statement("UPDATE clients SET status = 'active' WHERE status = 'pending'");
        DB::statement('ALTER TABLE clients DROP CONSTRAINT clients_status_check');
        T::checkIn('clients', 'status', ['active', 'inactive', 'archived']);
        Schema::table('organization_memberships', fn (Blueprint $table) => $table->dropColumn('name_prefix'));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['tagline', 'description']));
    }
};
