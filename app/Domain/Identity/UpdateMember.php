<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;

/**
 * Edits a team member: professional details (title, credentials, provider flag,
 * calendar colour) and roles. Role changes are the sensitive part: see AccessGuard
 * for who may assign or remove which role, and for the rule that an organization
 * keeps an active administrator. You cannot remove your own administrator role.
 */
final class UpdateMember
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly PermissionResolver $permissions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name_prefix?: ?string, title?: ?string, credentials?: ?string, is_provider?: bool, color?: ?string}  $profile
     * @param  list<string>  $roleIds  the member's complete set of roles after the change
     *
     * @throws DomainException
     */
    public function __invoke(OrganizationMembership $membership, array $profile, array $roleIds): OrganizationMembership
    {
        $this->guard->requirePermission('team.manage');
        $this->guard->assertInOrganization($membership);
        $actor = $this->guard->actor();

        $requested = $this->guard->resolveRoles($roleIds);
        if ($requested->isEmpty()) {
            throw new DomainException('Choose at least one role.', 'no_roles', 'roles');
        }

        $current = $this->guard->rolesOf($membership);
        $added = $requested->reject(fn ($role) => $current->contains('id', $role->id));
        $removed = $current->reject(fn ($role) => $requested->contains('id', $role->id));
        $rolesChange = $added->isNotEmpty() || $removed->isNotEmpty();

        if ($rolesChange) {
            // Before any transaction, so a refusal's audit row survives it.
            $this->guard->assertCanActOn($membership);
            $this->guard->assertCanManageRoles($added->concat($removed));

            if ($membership->id === $actor->id && $removed->contains('key', RoleTemplates::ORG_ADMIN)) {
                throw new DomainException('You cannot remove your own administrator role.', 'self_admin_role', 'roles');
            }
        }

        DB::transaction(function () use ($membership, $profile, $requested, $removed, $rolesChange, $added) {
            $this->guard->lockOrganization();
            $locked = OrganizationMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if ($rolesChange && $removed->contains('key', RoleTemplates::ORG_ADMIN)) {
                $this->guard->assertKeepsAnAdministrator($locked);
            }

            $locked->fill($this->cleaned($profile));
            $name = $locked->displayName();
            $this->audit->recordChanges('team.member_updated', $locked, summary: "Updated the details of {$name}");
            $locked->save();

            if ($rolesChange) {
                $before = $this->guard->rolesOf($locked)->pluck('name')->sort()->values()->all();
                $locked->roles()->sync($requested->pluck('id')->all());
                $after = $requested->pluck('name')->sort()->values()->all();

                $this->audit->record(
                    'team.member_roles_changed',
                    $locked,
                    before: ['roles' => $before],
                    after: ['roles' => $after],
                    metadata: ['added' => $added->pluck('name')->values()->all(), 'removed' => $removed->pluck('name')->values()->all()],
                    summary: "Changed the roles of {$name}",
                );
            }

            $membership->setRawAttributes($locked->getAttributes(), true);
        });

        if ($rolesChange) {
            $this->permissions->flush();
        }

        return $membership;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function cleaned(array $profile): array
    {
        $clean = [];
        foreach (['name_prefix', 'title', 'credentials', 'color'] as $key) {
            if (array_key_exists($key, $profile)) {
                $value = $profile[$key] === null ? null : trim((string) $profile[$key]);
                $value = $value === '' ? null : $value;

                if ($value !== null && $key !== 'color' && mb_strlen($value) > 100) {
                    throw new DomainException('That value is too long (100 characters at most).', 'too_long', $key);
                }
                if ($value !== null && $key === 'color' && ! preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                    throw new DomainException('Choose a colour like #5b8def.', 'invalid_color', 'color');
                }

                $clean[$key] = $key === 'color' && $value !== null ? strtolower($value) : $value;
            }
        }
        if (array_key_exists('is_provider', $profile)) {
            $clean['is_provider'] = (bool) $profile['is_provider'];
        }

        return $clean;
    }
}
