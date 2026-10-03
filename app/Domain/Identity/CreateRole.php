<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Role;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a custom organization role (key `custom_` + random, never system, never locked).
 * Only organization permissions can be granted (the database refuses platform.* on an
 * organization role as well) and only ones the creator holds themselves, unless they are
 * a full administrator.
 */
final class CreateRole
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $permissionKeys
     *
     * @throws DomainException
     */
    public function __invoke(string $name, ?string $description, array $permissionKeys): Role
    {
        $this->guard->requirePermission('roles.manage');

        $organization = $this->guard->organization();
        $name = $this->cleanName($name);
        $keys = RoleGrants::normalise($permissionKeys);

        $this->guard->assertCanChangePermissions($keys);

        $role = DB::transaction(function () use ($organization, $name, $description, $keys) {
            $this->guard->lockOrganization();

            if (Role::query()->forOrganization($organization->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
                throw new DomainException('A role with that name already exists.', 'role_name_taken', 'name');
            }

            do {
                $key = 'custom_'.Str::lower(Str::random(8));
            } while (Role::query()->forOrganization($organization->id)->where('key', $key)->exists());

            $role = new Role;
            $role->fill(['name' => $name, 'description' => $this->cleanDescription($description)]);
            $role->forceFill([
                'organization_id' => $organization->id,
                'scope' => Role::SCOPE_ORGANIZATION,
                'key' => $key,
                'is_system' => false,
                'is_locked' => false,
            ]);

            try {
                $role->save();
            } catch (UniqueConstraintViolationException) {
                throw new DomainException('A role with that name already exists.', 'role_name_taken', 'name');
            }

            RoleGrants::grant($role, $keys);

            $this->audit->record(
                'role.created',
                $role,
                after: ['name' => $name, 'permissions' => $keys],
                summary: "Created the role {$name}",
            );

            return $role;
        });

        return $role;
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '' || mb_strlen($name) > 100) {
            throw new DomainException('Give the role a name of up to 100 characters.', 'invalid_role_name', 'name');
        }

        return $name;
    }

    private function cleanDescription(?string $description): ?string
    {
        $description = $description === null ? null : trim($description);

        return $description === null || $description === '' ? null : mb_substr($description, 0, 500);
    }
}
