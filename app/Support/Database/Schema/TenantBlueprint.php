<?php

namespace App\Support\Database\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Schema conventions shared by every tenant-owned table, registered as Blueprint
 * macros so they hold by construction instead of by copy-paste.
 */
final class TenantBlueprint
{
    public static function register(): void
    {
        // organization_id NOT NULL → organizations. Organizations are archived, never deleted.
        Blueprint::macro('tenant', function () {
            /** @var Blueprint $this */
            return $this->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
        });

        // Target of composite tenant foreign keys from other tables.
        Blueprint::macro('tenantKey', function () {
            /** @var Blueprint $this */
            return $this->unique(['organization_id', 'id']);
        });

        // (organization_id, $column) → $on(organization_id, id): a row can only
        // reference a row of the same organization, enforced by PostgreSQL.
        Blueprint::macro('tenantForeign', function (string $column, string $on, string $onDelete = 'restrict') {
            /** @var Blueprint $this */
            return $this->foreign(['organization_id', $column])
                ->references(['organization_id', 'id'])
                ->on($on)
                ->onDelete($onDelete);
        });

        // live | demo, immutable; demo rows are excluded from real reporting.
        Blueprint::macro('recordEnvironment', function () {
            /** @var Blueprint $this */
            return $this->string('record_environment', 8)->default('live');
        });
    }

    /** CHECK (column IN (...)) — values are written literally so old migrations stay frozen. */
    public static function checkIn(string $table, string $column, array $values, ?string $name = null): void
    {
        $list = implode(', ', array_map(fn (string $v) => DB::getPdo()->quote($v), $values));
        $name ??= "{$table}_{$column}_check";

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$column} IN ({$list}))");
    }

    public static function check(string $table, string $name, string $expression): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name}_check CHECK ({$expression})");
    }

    /**
     * Rejects UPDATE and DELETE on an insert-only table.
     *
     * With $allowDemoPurge, a DELETE of a row whose record_environment is 'demo'
     * is allowed inside a transaction that has SET LOCAL app.purging_demo = 'on'
     * (the demo-data purge). Live rows can never be deleted or changed.
     */
    public static function insertOnly(string $table, bool $allowDemoPurge = false): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_row_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'table % is insert-only', TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION forbid_row_mutation_except_demo_purge() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE'
                    AND OLD.record_environment = 'demo'
                    AND current_setting('app.purging_demo', true) = 'on' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'table % is insert-only', TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        $function = $allowDemoPurge ? 'forbid_row_mutation_except_demo_purge' : 'forbid_row_mutation';

        DB::unprepared(<<<SQL
            CREATE TRIGGER {$table}_insert_only
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION {$function}();
        SQL);
    }
}
