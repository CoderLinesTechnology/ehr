<?php

namespace App\Domain\Identity;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors PermissionRegistry into `permissions`, creates the platform roles,
 * and grants permissions that are NEW to the catalogue to the system roles
 * whose template includes them (locked roles get everything of their scope).
 * Existing grants an organization changed on purpose are never touched.
 * Idempotent: safe to run on every deploy.
 */
final class SyncPermissionCatalogue
{
    /** @return array{created: list<string>, removed: list<string>} */
    public function __invoke(): array
    {
        return DB::transaction(function () {
            $existing = Permission::query()->pluck('key')->all();
            $catalogue = PermissionRegistry::all();
            $now = now();

            Permission::query()->upsert(
                array_map(fn (string $key, array $p) => [
                    'key' => $key, 'scope' => $p['scope'], 'group' => $p['group'], 'label' => $p['label'],
                    'description' => $p['description'] ?: null, 'is_sensitive' => $p['sensitive'],
                    'created_at' => $now, 'updated_at' => $now,
                ], array_keys($catalogue), $catalogue),
                ['key'],
                ['scope', 'group', 'label', 'description', 'is_sensitive', 'updated_at'],
            );

            $removed = array_values(array_diff($existing, array_keys($catalogue)));
            if ($removed !== []) {
                Permission::query()->whereIn('key', $removed)->delete(); // grants cascade
            }

            $this->ensurePlatformRoles();

            $created = array_values(array_diff(array_keys($catalogue), $existing));
            if ($created !== [] && $existing !== []) {
                $this->grantNewPermissionsToSystemRoles($created);
            }

            return ['created' => $created, 'removed' => $removed];
        });
    }

    private function ensurePlatformRoles(): void
    {
        foreach (RoleTemplates::platform() as $key => $template) {
            $role = Role::query()->platform()->where('key', $key)->first();
            if ($role !== null) {
                continue;
            }

            $role = new Role;
            $role->forceFill([
                'scope' => Role::SCOPE_PLATFORM, 'organization_id' => null, 'key' => $key,
                'name' => $template['name'], 'description' => $template['description'],
                'is_system' => true, 'is_locked' => $template['locked'],
            ])->save();

            self::grant($role, RoleTemplates::resolve($template, Role::SCOPE_PLATFORM));
        }
    }

    /** @param list<string> $created */
    private function grantNewPermissionsToSystemRoles(array $created): void
    {
        $templates = [
            Role::SCOPE_ORGANIZATION => RoleTemplates::organization(),
            Role::SCOPE_PLATFORM => RoleTemplates::platform(),
        ];

        Role::query()->where('is_system', true)->chunkById(500, function ($roles) use ($templates, $created) {
            foreach ($roles as $role) {
                $template = $templates[$role->scope][$role->key] ?? null;
                if ($template === null) {
                    continue;
                }
                $wanted = array_intersect(RoleTemplates::resolve($template, $role->scope), $created);
                self::grant($role, array_values($wanted));
            }
        });
    }

    /** @param list<string> $keys */
    public static function grant(Role $role, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        RolePermission::query()->insertOrIgnore(array_map(fn (string $key) => [
            'role_id' => $role->id, 'permission_key' => $key, 'scope' => $role->scope,
        ], $keys));
    }
}
