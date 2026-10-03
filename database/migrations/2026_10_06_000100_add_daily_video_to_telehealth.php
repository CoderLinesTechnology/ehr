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
        // Telehealth runs on Daily.co: one Daily room per session. Daily names the room; `join_url` (encrypted, hidden)
        // keeps the room's address, which is also the client link. nbf/exp mirror what Daily currently has, so the
        // room is updated only when the session needs a different window. The name stays on the session after the
        // room is deleted (the recording webhook arrives later and finds the session by it); the unique index on it is
        // built concurrently by 2026_10_06_000120.
        Schema::table('telehealth_sessions', function (Blueprint $table) {
            $table->string('provider_room_name', 128)->nullable();
            $table->timestampTz('provider_room_nbf')->nullable();
            $table->timestampTz('provider_room_exp')->nullable();
        });
        T::check('telehealth_sessions', 'provider_room',
            'provider_room_name IS NULL OR (join_url IS NOT NULL AND provider_room_nbf IS NOT NULL AND provider_room_exp IS NOT NULL AND provider_room_exp > provider_room_nbf)');
        T::check('telehealth_sessions', 'provider_room_window', 'provider_room_name IS NOT NULL OR (provider_room_nbf IS NULL AND provider_room_exp IS NULL)');

        // A recording is either a file WellNest stores (manual upload: path, hash, size) or one the video vendor
        // stores (Daily cloud recording: provider id; downloaded through a short-lived link minted per download).
        // The consent foreign key is unchanged: the database still refuses a recording of a session without consent.
        Schema::table('session_recordings', function (Blueprint $table) {
            $table->string('storage_path', 200)->nullable()->change();
            $table->char('sha256', 64)->nullable()->change();
            $table->unsignedBigInteger('size_bytes')->nullable()->change();
            $table->string('provider_key', 32)->nullable();
            $table->string('provider_recording_id', 64)->nullable();
        });
        DB::statement('ALTER TABLE session_recordings DROP CONSTRAINT session_recordings_size_check');
        T::check('session_recordings', 'size', 'size_bytes IS NULL OR size_bytes > 0');
        T::check('session_recordings', 'source', '(storage_path IS NULL) <> (provider_recording_id IS NULL)');
        T::check('session_recordings', 'file', 'storage_path IS NULL OR (sha256 IS NOT NULL AND size_bytes IS NOT NULL)');
        T::check('session_recordings', 'provider', 'provider_recording_id IS NULL OR provider_key IS NOT NULL');
    }

    public function down(): void
    {
        // Vendor-stored recordings have no file: they cannot survive the old shape (delete them at Daily first).
        foreach (['source', 'file', 'provider', 'size'] as $check) {
            DB::statement("ALTER TABLE session_recordings DROP CONSTRAINT IF EXISTS session_recordings_{$check}_check");
        }
        Schema::table('session_recordings', function (Blueprint $table) {
            $table->dropColumn(['provider_key', 'provider_recording_id']);
            $table->string('storage_path', 200)->nullable(false)->change();
            $table->char('sha256', 64)->nullable(false)->change();
            $table->unsignedBigInteger('size_bytes')->nullable(false)->change();
        });
        T::check('session_recordings', 'size', 'size_bytes > 0');

        DB::statement('ALTER TABLE telehealth_sessions DROP CONSTRAINT IF EXISTS telehealth_sessions_provider_room_check');
        DB::statement('ALTER TABLE telehealth_sessions DROP CONSTRAINT IF EXISTS telehealth_sessions_provider_room_window_check');
        Schema::table('telehealth_sessions', function (Blueprint $table) {
            $table->dropColumn(['provider_room_name', 'provider_room_nbf', 'provider_room_exp']);
        });
    }
};
