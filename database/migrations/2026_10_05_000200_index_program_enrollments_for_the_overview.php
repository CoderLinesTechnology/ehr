<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two read paths of the Programs screens, measured on 40,000 enrollments of one organization:
 *
 *  - the overview's participant figures count active and completed LIVE enrollments grouped by program: an
 *    index-only scan on (organization, status, environment, program) instead of reading every row, ended history included;
 *  - a program's participant list ordered by admission date (the "All" and "Ended" views): the keyset-ordered index
 *    serves ORDER BY ... LIMIT without sorting the program's whole history. (The default "Enrolled" view is served by
 *    the one-open-enrollment partial index.)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX program_enrollments_counts_idx ON program_enrollments (organization_id, status, record_environment, program_id)');
        DB::statement('CREATE INDEX program_enrollments_admitted_idx ON program_enrollments (organization_id, program_id, admitted_at DESC, id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS program_enrollments_counts_idx');
        DB::statement('DROP INDEX IF EXISTS program_enrollments_admitted_idx');
    }
};
