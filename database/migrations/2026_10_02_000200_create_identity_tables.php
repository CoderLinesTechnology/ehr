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
        // Code-defined catalogue (PermissionRegistry), synced by `permissions:sync`.
        Schema::create('permissions', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->string('scope', 16);
            $table->string('group', 60);
            $table->string('label', 150);
            $table->string('description', 500)->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->timestampsTz();

            $table->unique(['key', 'scope']);
        });
        T::checkIn('permissions', 'scope', ['organization', 'platform']);

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('scope', 16);
            $table->string('key', 60);
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->timestampsTz();

            $table->unique(['id', 'scope']);
            $table->unique(['organization_id', 'id']);
        });
        T::checkIn('roles', 'scope', ['organization', 'platform']);
        T::check('roles', 'scope_owner', "(scope = 'platform' AND organization_id IS NULL) OR (scope = 'organization' AND organization_id IS NOT NULL)");
        DB::statement('CREATE UNIQUE INDEX roles_org_key_unique ON roles (organization_id, key) WHERE organization_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX roles_org_name_unique ON roles (organization_id, lower(name)) WHERE organization_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX roles_platform_key_unique ON roles (key) WHERE organization_id IS NULL');

        // scope is repeated here so that both composite FKs must agree: an
        // organization role can never be granted a platform permission.
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('role_id');
            $table->string('permission_key', 100);
            $table->string('scope', 16);

            $table->primary(['role_id', 'permission_key']);
            $table->foreign(['role_id', 'scope'])->references(['id', 'scope'])->on('roles')->cascadeOnDelete();
            $table->foreign(['permission_key', 'scope'])->references(['key', 'scope'])->on('permissions')->cascadeOnDelete();
            $table->index('permission_key');
        });

        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenant();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 16);
            $table->string('invited_email', 254)->nullable();
            $table->string('invitation_token_hash', 64)->nullable()->unique();
            $table->timestampTz('invitation_expires_at')->nullable();
            $table->foreignUuid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 100)->nullable();
            $table->string('credentials', 100)->nullable();
            $table->boolean('is_provider')->default(false);
            $table->char('color', 7)->nullable();
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestampsTz();

            $table->tenantKey();
            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');
        });
        T::checkIn('organization_memberships', 'status', ['invited', 'active', 'suspended', 'deactivated']);
        T::check('organization_memberships', 'identity', "(status = 'invited' AND invited_email IS NOT NULL) OR (status <> 'invited' AND user_id IS NOT NULL)");
        T::check('organization_memberships', 'color', "color IS NULL OR color ~ '^#[0-9a-fA-F]{6}\$'");
        DB::statement("CREATE UNIQUE INDEX organization_memberships_pending_invite_unique ON organization_memberships (organization_id, lower(invited_email)) WHERE status = 'invited'");

        Schema::create('membership_roles', function (Blueprint $table) {
            $table->tenant();
            $table->uuid('membership_id');
            $table->uuid('role_id');

            $table->primary(['membership_id', 'role_id']);
            $table->tenantForeign('membership_id', 'organization_memberships', 'cascade');
            $table->tenantForeign('role_id', 'roles');
            $table->index(['organization_id', 'role_id']);
        });

        Schema::create('platform_user_roles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('role_id');
            $table->string('scope', 16)->default('platform');
            $table->foreignUuid('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('granted_at')->useCurrent();

            $table->primary(['user_id', 'role_id']);
            $table->foreign(['role_id', 'scope'])->references(['id', 'scope'])->on('roles')->restrictOnDelete();
        });
        T::checkIn('platform_user_roles', 'scope', ['platform']);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_user_roles');
        Schema::dropIfExists('membership_roles');
        Schema::dropIfExists('organization_memberships');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
