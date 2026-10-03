<?php

use App\Support\Database\Schema\TenantBlueprint as T;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client types (adult / minor / couple), billing type, "virtual" primary location, several e-mails and phones per
 * client, a typed relationship on client contacts, and couples (a couple is a client linked to its two members).
 * Schema only: the data step (contact points from clients.email / clients.phone, relationship types from the
 * free-text labels) is the next migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('client_type', 16)->default('adult');
            $table->string('billing_type', 16)->default('self_pay');
            $table->boolean('is_virtual')->default(false);
            // Every e-mail and phone of the client (client_contact_points), kept by a trigger: search reads it.
            $table->text('contact_search')->nullable();
        });
        T::checkIn('clients', 'client_type', ['adult', 'minor', 'couple']);
        T::checkIn('clients', 'billing_type', ['self_pay', 'insurance']);
        // "Primary location: Virtual (telehealth)" is instead of a place, never as well as one.
        T::check('clients', 'virtual_location', 'NOT (is_virtual AND primary_location_id IS NOT NULL)');

        // search_text gains the client's other e-mails and phones. A generated column cannot be redefined in place
        // on PostgreSQL 16, so it is dropped and re-added (its trigram index goes with it and is rebuilt).
        DB::statement('ALTER TABLE clients DROP COLUMN search_text');
        DB::statement(<<<'SQL'
            ALTER TABLE clients ADD COLUMN search_text text GENERATED ALWAYS AS (
                lower(
                    first_name || ' ' || coalesce(preferred_name, '') || ' ' || last_name || ' '
                    || coalesce(email, '') || ' ' || coalesce(phone, '') || ' ' || coalesce(contact_search, '')
                )
            ) STORED
        SQL);
        DB::statement('CREATE INDEX clients_search_text_trgm ON clients USING gin (search_text gin_trgm_ops)');
        // "Has this organization any demo clients?" (the shell's banner, the list's Records filter): one probe.
        DB::statement("CREATE INDEX clients_demo_idx ON clients (organization_id) WHERE record_environment = 'demo'");
        // The list's Client Type filter (and its count) for the minority types.
        DB::statement("CREATE INDEX clients_type_idx ON clients (organization_id, client_type) WHERE client_type <> 'adult'");

        Schema::create('client_contact_points', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->uuid('client_id');
            $table->string('kind', 8);
            // E-mail lower-cased; phone in E.164 (PhoneNumbers::normalize for the organization's country).
            $table->string('value', 254);
            $table->string('label', 8)->default('other');
            $table->boolean('is_primary')->default(false);
            $table->smallInteger('sort')->default(0);
            $table->timestampsTz();

            $table->tenantForeign('client_id', 'clients', 'cascade');
            $table->unique(['organization_id', 'client_id', 'kind', 'value']);
        });
        T::checkIn('client_contact_points', 'kind', ['email', 'phone']);
        T::checkIn('client_contact_points', 'label', ['mobile', 'home', 'work', 'other']);
        DB::statement('CREATE UNIQUE INDEX client_contact_points_one_primary ON client_contact_points (organization_id, client_id, kind) WHERE is_primary');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION client_contact_points_sync_search() RETURNS trigger AS $$
            DECLARE
                org uuid;
                target uuid;
                joined text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    org := OLD.organization_id; target := OLD.client_id;
                ELSE
                    org := NEW.organization_id; target := NEW.client_id;
                END IF;

                SELECT string_agg(p.value, ' ' ORDER BY p.kind, p.sort, p.value) INTO joined
                FROM client_contact_points p
                WHERE p.organization_id = org AND p.client_id = target;

                -- A client being deleted (demo purge, cascade) is already gone: nothing to update.
                UPDATE clients SET contact_search = joined
                WHERE id = target AND organization_id = org AND contact_search IS DISTINCT FROM joined;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER client_contact_points_search
                AFTER INSERT OR UPDATE OR DELETE ON client_contact_points
                FOR EACH ROW EXECUTE FUNCTION client_contact_points_sync_search();
        SQL);

        Schema::table('client_contacts', function (Blueprint $table) {
            $table->string('relationship_type', 16)->nullable()->after('relationship');
        });
        DB::statement("ALTER TABLE client_contacts ADD CONSTRAINT client_contacts_relationship_type_check CHECK (relationship_type IS NULL OR relationship_type IN ('parent', 'guardian', 'partner', 'spouse', 'sibling', 'child', 'friend', 'other'))");

        Schema::create('client_couple_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->recordEnvironment();
            $table->uuid('couple_client_id');
            $table->uuid('member_client_id');
            $table->timestampsTz();

            // Same organization AND same environment for the couple and each member; either going takes the link.
            $table->foreign(['organization_id', 'couple_client_id', 'record_environment'])
                ->references(['organization_id', 'id', 'record_environment'])->on('clients')->cascadeOnDelete();
            $table->foreign(['organization_id', 'member_client_id', 'record_environment'])
                ->references(['organization_id', 'id', 'record_environment'])->on('clients')->cascadeOnDelete();
            $table->unique(['organization_id', 'couple_client_id', 'member_client_id']);
            $table->index(['organization_id', 'member_client_id']);
        });
        T::checkIn('client_couple_members', 'record_environment', ['live', 'demo']);
        T::check('client_couple_members', 'distinct', 'couple_client_id <> member_client_id');
    }

    public function down(): void
    {
        Schema::dropIfExists('client_couple_members');
        DB::statement('ALTER TABLE client_contacts DROP CONSTRAINT IF EXISTS client_contacts_relationship_type_check');
        Schema::table('client_contacts', fn (Blueprint $table) => $table->dropColumn('relationship_type'));
        Schema::dropIfExists('client_contact_points');
        DB::statement('DROP FUNCTION IF EXISTS client_contact_points_sync_search()');

        DB::statement('DROP INDEX IF EXISTS clients_type_idx');
        DB::statement('DROP INDEX IF EXISTS clients_demo_idx');
        DB::statement('ALTER TABLE clients DROP COLUMN search_text');
        DB::statement(<<<'SQL'
            ALTER TABLE clients ADD COLUMN search_text text GENERATED ALWAYS AS (
                lower(
                    first_name || ' ' || coalesce(preferred_name, '') || ' ' || last_name || ' '
                    || coalesce(email, '') || ' ' || coalesce(phone, '')
                )
            ) STORED
        SQL);
        DB::statement('CREATE INDEX clients_search_text_trgm ON clients USING gin (search_text gin_trgm_ops)');
        DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_virtual_location_check');
        DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_client_type_check');
        DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_billing_type_check');
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['client_type', 'billing_type', 'is_virtual', 'contact_search']));
    }
};
