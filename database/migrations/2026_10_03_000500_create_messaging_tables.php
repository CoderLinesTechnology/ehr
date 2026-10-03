<?php

use App\Support\Database\Schema\TenantBlueprint as T;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Reactions a participant may leave (kept in step with App\Domain\Messaging\Reactions). */
    private const EMOJI = ['👍', '❤️', '😊', '🙏', '🎉', '👏'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('last_seen_at')->nullable();   // presence: written at most once a minute
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->recordEnvironment();
            $table->string('kind', 8);
            $table->string('title', 120)->nullable();
            $table->uuid('client_id')->nullable();
            // One direct thread per pair of staff, one client thread per (client, staff member).
            $table->string('unique_key', 120)->nullable();
            $table->uuid('created_by_membership_id');
            $table->timestampTz('last_message_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->tenantKey();
            $table->unique(['organization_id', 'unique_key']);
            $table->tenantForeign('created_by_membership_id', 'organization_memberships');
            // The client and the thread agree on live/demo; demo clients take their threads with them.
            $table->foreign(['organization_id', 'client_id', 'record_environment'])
                ->references(['organization_id', 'id', 'record_environment'])->on('clients')->cascadeOnDelete();
            $table->index(['organization_id', 'client_id']);
        });
        T::checkIn('conversations', 'kind', ['direct', 'group', 'client']);
        T::checkIn('conversations', 'record_environment', ['live', 'demo']);
        T::check('conversations', 'shape', "(kind = 'client') = (client_id IS NOT NULL) AND (kind <> 'group' OR title IS NOT NULL) AND (kind = 'client' OR record_environment = 'live')");

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->uuid('conversation_id');
            $table->uuid('membership_id');
            $table->timestampTz('joined_at', 6)->useCurrent();
            $table->timestampTz('left_at', 6)->nullable();
            $table->timestampTz('last_read_at', 6)->nullable();
            $table->uuid('last_read_message_id')->nullable();
            $table->boolean('muted')->default(false);
            $table->timestampsTz(6);

            $table->tenantKey();
            $table->unique(['conversation_id', 'membership_id']);
            $table->tenantForeign('conversation_id', 'conversations', 'cascade');
            $table->tenantForeign('membership_id', 'organization_memberships');
            $table->index(['organization_id', 'membership_id', 'conversation_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->uuid('conversation_id');
            $table->uuid('sender_membership_id');
            $table->text('body')->default('');
            $table->timestampTz('retracted_at', 6)->nullable();
            $table->timestampTz('edited_at', 6)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->nullable();

            $table->tenantKey();
            $table->tenantForeign('conversation_id', 'conversations', 'cascade');
            // Only a current or former participant of the thread can have written in it.
            $table->foreign(['conversation_id', 'sender_membership_id'])
                ->references(['conversation_id', 'membership_id'])->on('conversation_participants');
            $table->index(['conversation_id', 'created_at', 'id']);
        });
        T::check('messages', 'body_length', 'char_length(body) <= 5000');
        T::check('messages', 'retracted_empty', "retracted_at IS NULL OR body = ''");

        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->tenantForeign('last_read_message_id', 'messages');
        });

        // A message is never deleted or rewritten; the only change allowed is the sender's retraction
        // (body emptied, retracted_at stamped). Rows go only with a demo-data purge.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION messages_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('app.purging_demo', true) = 'on' THEN RETURN OLD; END IF;
                    RAISE EXCEPTION 'messages are never deleted' USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.id <> OLD.id OR NEW.organization_id <> OLD.organization_id OR NEW.conversation_id <> OLD.conversation_id
                    OR NEW.sender_membership_id <> OLD.sender_membership_id OR NEW.created_at <> OLD.created_at THEN
                    RAISE EXCEPTION 'message identity is immutable' USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.body IS DISTINCT FROM OLD.body AND NOT (OLD.retracted_at IS NULL AND NEW.retracted_at IS NOT NULL AND NEW.body = '') THEN
                    RAISE EXCEPTION 'a message body can only be retracted' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.retracted_at IS NOT NULL AND NEW.retracted_at IS DISTINCT FROM OLD.retracted_at THEN
                    RAISE EXCEPTION 'a retraction is final' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER messages_guard BEFORE UPDATE OR DELETE ON messages FOR EACH ROW EXECUTE FUNCTION messages_guard();
        SQL);

        Schema::create('message_reactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->uuid('conversation_id');
            $table->uuid('message_id');
            $table->uuid('membership_id');
            $table->string('emoji', 16);
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->tenantKey();
            $table->unique(['message_id', 'membership_id']);   // one reaction per participant per message
            $table->tenantForeign('message_id', 'messages', 'cascade');
            $table->foreign(['conversation_id', 'membership_id'])
                ->references(['conversation_id', 'membership_id'])->on('conversation_participants')->cascadeOnDelete();
        });
        T::checkIn('message_reactions', 'emoji', self::EMOJI);

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->uuid('conversation_id');
            $table->uuid('message_id');
            $table->string('original_name', 200);
            $table->string('mime', 20);
            $table->unsignedInteger('size_bytes');
            $table->string('storage_path', 200)->unique();
            $table->char('sha256', 64);
            $table->timestampTz('purged_at', 6)->nullable();   // file removed when the message was retracted
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->tenantKey();
            $table->tenantForeign('message_id', 'messages', 'cascade');
            $table->index(['message_id']);
        });
        T::checkIn('message_attachments', 'mime', ['application/pdf', 'image/png', 'image/jpeg']);
        T::check('message_attachments', 'size', 'size_bytes <= 10485760');
    }

    public function down(): void
    {
        Schema::table('conversation_participants', fn (Blueprint $t) => $t->dropForeign(['organization_id', 'last_read_message_id']));
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('message_reactions');
        Schema::dropIfExists('messages');
        DB::unprepared('DROP FUNCTION IF EXISTS messages_guard()');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('last_seen_at'));
    }
};
