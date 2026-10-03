<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data step for the client-model change. Idempotent (safe to re-run; it only fills what is missing):
 *
 *  - one PRIMARY contact point per existing clients.email / clients.phone (both are already normalised by the
 *    domain: e-mail lower-cased, phone E.164; lower() again is harmless). clients.email / clients.phone stay the
 *    primary values, so nothing else changes for readers of those columns;
 *  - client_contacts.relationship_type from the free-text label where the meaning is obvious, else 'other'. The
 *    label itself stays (it is what the contact list shows).
 *
 * Only counts are logged, never values.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // One statement per kind, then the search projection once (the per-row trigger would rewrite each client
            // row once per point; on a large table that is the slow part of this migration).
            DB::statement('ALTER TABLE client_contact_points DISABLE TRIGGER client_contact_points_search');

            $emails = DB::affectingStatement(<<<'SQL'
                INSERT INTO client_contact_points (id, organization_id, client_id, kind, value, label, is_primary, sort, created_at, updated_at)
                SELECT gen_random_uuid(), c.organization_id, c.id, 'email', lower(btrim(c.email)), 'home', true, 0, now(), now()
                FROM clients c
                WHERE c.email IS NOT NULL AND btrim(c.email) <> ''
                  AND NOT EXISTS (
                      SELECT 1 FROM client_contact_points p
                      WHERE p.organization_id = c.organization_id AND p.client_id = c.id AND p.kind = 'email'
                  )
                ON CONFLICT DO NOTHING
            SQL);

            $phones = DB::affectingStatement(<<<'SQL'
                INSERT INTO client_contact_points (id, organization_id, client_id, kind, value, label, is_primary, sort, created_at, updated_at)
                SELECT gen_random_uuid(), c.organization_id, c.id, 'phone', btrim(c.phone), 'mobile', true, 0, now(), now()
                FROM clients c
                WHERE c.phone IS NOT NULL AND btrim(c.phone) <> ''
                  AND NOT EXISTS (
                      SELECT 1 FROM client_contact_points p
                      WHERE p.organization_id = c.organization_id AND p.client_id = c.id AND p.kind = 'phone'
                  )
                ON CONFLICT DO NOTHING
            SQL);

            DB::statement('ALTER TABLE client_contact_points ENABLE TRIGGER client_contact_points_search');

            DB::statement(<<<'SQL'
                UPDATE clients c SET contact_search = agg.joined
                FROM (
                    SELECT organization_id, client_id, string_agg(value, ' ' ORDER BY kind, sort, value) AS joined
                    FROM client_contact_points GROUP BY organization_id, client_id
                ) agg
                WHERE c.organization_id = agg.organization_id AND c.id = agg.client_id
                  AND c.contact_search IS DISTINCT FROM agg.joined
            SQL);

            $typed = DB::affectingStatement(<<<'SQL'
                UPDATE client_contacts SET relationship_type = CASE
                    WHEN lower(btrim(relationship)) IN ('mother', 'father', 'parent', 'mum', 'mom', 'mummy', 'mommy', 'dad', 'daddy', 'stepmother', 'stepfather', 'step-mother', 'step-father') THEN 'parent'
                    WHEN lower(btrim(relationship)) IN ('guardian', 'legal guardian', 'carer', 'caregiver', 'foster parent', 'foster mother', 'foster father') THEN 'guardian'
                    WHEN lower(btrim(relationship)) IN ('partner', 'boyfriend', 'girlfriend', 'fiance', 'fiancé', 'fiancee', 'fiancée') THEN 'partner'
                    WHEN lower(btrim(relationship)) IN ('spouse', 'wife', 'husband') THEN 'spouse'
                    WHEN lower(btrim(relationship)) IN ('sibling', 'sister', 'brother') THEN 'sibling'
                    WHEN lower(btrim(relationship)) IN ('child', 'son', 'daughter') THEN 'child'
                    WHEN lower(btrim(relationship)) IN ('friend', 'best friend') THEN 'friend'
                    ELSE 'other'
                END
                WHERE relationship_type IS NULL
            SQL);

            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                fwrite(STDOUT, "  client contact points: {$emails} e-mail, {$phones} phone; contacts typed: {$typed}\n");
            }
        });
    }

    /** The rows this step wrote are indistinguishable from later ones; the schema migration's down() drops them. */
    public function down(): void {}
};
