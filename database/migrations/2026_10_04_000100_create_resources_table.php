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
        Schema::create('resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->string('type', 16);
            $table->string('title', 120);
            $table->string('summary', 300);
            $table->text('body')->nullable();
            // Private `local` disk path (resources/{organization}/{random}.pdf); never a client-chosen name.
            $table->string('file_path', 200)->nullable();
            $table->unsignedInteger('file_size_bytes')->nullable();
            // Videos are links, opened in a new tab: never embedded, never fetched by the server.
            $table->string('external_url', 2048)->nullable();
            $table->unsignedSmallInteger('reading_minutes')->nullable();
            $table->string('audience', 12)->default('everyone');
            $table->boolean('is_featured')->default(false);
            $table->string('status', 12)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->tenantKey();
            // "Latest": published, newest first, optionally one type.
            $table->index(['organization_id', 'status', 'published_at', 'id']);
            $table->index(['organization_id', 'type', 'status', 'published_at']);
        });

        T::checkIn('resources', 'type', ['guide', 'form', 'document', 'video', 'faq']);
        T::checkIn('resources', 'audience', ['staff', 'clients', 'everyone']);
        T::checkIn('resources', 'status', ['draft', 'published', 'archived']);
        // A published resource always carries its publication time (the "latest" scope needs both).
        T::check('resources', 'published_at', "status = 'draft' OR published_at IS NOT NULL");
        // Only published resources are featured.
        T::check('resources', 'featured', "NOT is_featured OR status = 'published'");
        T::check('resources', 'external_url', "external_url IS NULL OR external_url ~ '^https://[^/@[:space:]]+([/?#]|$)'");
        T::check('resources', 'reading_minutes', 'reading_minutes IS NULL OR reading_minutes BETWEEN 1 AND 600');
        T::check('resources', 'body_length', 'body IS NULL OR char_length(body) <= 20000');

        // Featured row: at most a handful per organization, read on every Resources page.
        DB::statement("CREATE INDEX resources_featured_idx ON resources (organization_id, published_at DESC) WHERE is_featured");
        // Search by title/summary; the trigram GIN index serves ILIKE '%term%'.
        DB::statement(<<<'SQL'
            ALTER TABLE resources ADD COLUMN search_text text GENERATED ALWAYS AS (lower(title || ' ' || summary)) STORED
        SQL);
        DB::statement('CREATE INDEX resources_search_text_trgm ON resources USING gin (search_text gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
