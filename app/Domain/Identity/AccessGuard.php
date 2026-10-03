<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The privilege-escalation rules of team and role management, in one place so
 * every action (and the screens that decide what to offer) answers the same way.
 *
 * Vocabulary:
 *  - the acting membership is the signed-in person's membership in the current organization;
 *  - a "full administrator" holds `roles.manage` AND the Organization Administrator role;
 *  - the "manageable" permissions are those the actor may grant or revoke: every organization
 *    permission for a full administrator, otherwise exactly the permissions the actor holds.
 *
 * Rules enforced here (each refusal is a DomainException with a safe message):
 *  1. permissions can only be granted or revoked within the manageable set;
 *  2. a role can only be assigned or removed when every permission in it is manageable, and the
 *     Organization Administrator role only by a full administrator;
 *  3. a member whose access exceeds the actor's cannot have their roles or status changed by that actor;
 *  4. an organization always keeps at least one active Organization Administrator.
 *
 * Escalation refusals (1–3) are also written to the audit log: an honest screen never offers them,
 * so one that arrives is a crafted request and the organization's administrators should be able to see it.
 * Call the assert* methods before opening a transaction so that audit row survives the refusal.
 */
final class AccessGuard
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PermissionResolver $permissions,
        private readonly AuditLogger $audit,
    ) {}

    public function organization(): Organization
    {
        return $this->tenant->organizationOrFail();
    }

    public function actor(): OrganizationMembership
    {
        return $this->tenant->membership()
            ?? throw new DomainException('You must be signed in to this organization to do that.', 'no_actor');
    }

    /**
     * A record of another organization (or a platform role) does not exist as far as the current
     * organization is concerned: the same "not found" a tenant-scoped route binding would give.
     * Every action calls this on the model it is handed before doing anything else.
     *
     * @throws ModelNotFoundException
     */
    public function assertInOrganization(Model $record): void
    {
        if (($record->getAttributes()['organization_id'] ?? null) !== $this->organization()->id) {
            throw (new ModelNotFoundException)->setModel($record::class, [$record->getKey()]);
        }
    }

    public function actorHas(string $permission): bool
    {
        $actor = $this->tenant->membership();

        return $actor !== null && $actor->isActive() && $this->permissions->membershipHas($actor, $permission);
    }

    /** @throws DomainException */
    public function requirePermission(string $permission): void
    {
        if (! $this->actorHas($permission)) {
            throw new DomainException('You do not have permission to do that.', 'forbidden');
        }
    }

    public function holdsAdminRole(OrganizationMembership $membership): bool
    {
        return DB::table('membership_roles')
            ->join('roles', 'roles.id', '=', 'membership_roles.role_id')
            ->where('membership_roles.membership_id', $membership->id)
            ->where('membership_roles.organization_id', $membership->organization_id)
            ->where('roles.key', RoleTemplates::ORG_ADMIN)
            ->exists();
    }

    /** `roles.manage` AND the Organization Administrator role: the only actor who may grant what they do not hold. */
    public function actorIsFullAdmin(): bool
    {
        return $this->actorHas('roles.manage') && $this->holdsAdminRole($this->actor());
    }

    /** @return list<string> organization permission keys the actor may grant or revoke */
    public function manageablePermissions(): array
    {
        if ($this->actorIsFullAdmin()) {
            return PermissionRegistry::keys(PermissionRegistry::SCOPE_ORGANIZATION);
        }

        return array_keys($this->permissions->membershipPermissions($this->actor()));
    }

    /**
     * Roles from this organization only; an unknown or foreign id is refused,
     * never silently dropped (a crafted request must not half-succeed).
     *
     * @param  array<int, mixed>  $roleIds
     * @return Collection<int, Role>
     */
    public function resolveRoles(array $roleIds, string $field = 'roles'): Collection
    {
        $ids = array_values(array_unique(array_map('strval', array_filter($roleIds, 'is_scalar'))));

        foreach ($ids as $id) {
            if (! Str::isUuid($id)) {
                throw new DomainException('One of the selected roles is not available.', 'invalid_role', $field);
            }
        }

        $roles = $ids === [] ? new Collection : Role::query()->forOrganization($this->organization()->id)->whereIn('id', $ids)->get();

        if ($roles->count() !== count($ids)) {
            throw new DomainException('One of the selected roles is not available.', 'invalid_role', $field);
        }

        return $roles;
    }

    /** @return Collection<int, Role> */
    public function rolesOf(OrganizationMembership $membership): Collection
    {
        return Role::query()
            ->forOrganization($membership->organization_id)
            ->whereIn('id', DB::table('membership_roles')->where('membership_id', $membership->id)->select('role_id'))
            ->get();
    }

    /**
     * Permission keys held by each role, in one query.
     *
     * @param  iterable<Role>  $roles
     * @return array<string, list<string>> role id => keys
     */
    public function permissionsOf(iterable $roles): array
    {
        $ids = collect($roles)->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $byRole = array_fill_keys($ids, []);
        foreach (DB::table('role_permissions')->whereIn('role_id', $ids)->get(['role_id', 'permission_key']) as $row) {
            $byRole[$row->role_id][] = $row->permission_key;
        }

        return $byRole;
    }

    /**
     * Which of these roles the actor may assign to, or remove from, a member.
     *
     * @param  iterable<Role>  $roles
     * @return list<string> role ids
     */
    public function manageableRoleIds(iterable $roles): array
    {
        $roles = collect($roles);

        if ($this->actorIsFullAdmin()) {
            return $roles->pluck('id')->values()->all();
        }

        $manageable = array_flip($this->manageablePermissions());
        $permissions = $this->permissionsOf($roles);

        return $roles
            ->filter(fn (Role $role) => $role->key !== RoleTemplates::ORG_ADMIN
                && array_diff_key(array_flip($permissions[$role->id] ?? []), $manageable) === [])
            ->pluck('id')->values()->all();
    }

    /**
     * May the actor change this member's access (roles, status)? Always true for
     * oneself (the self rules are separate) and for a full administrator.
     */
    public function canActOn(OrganizationMembership $target): bool
    {
        if ($target->id === $this->actor()->id || $this->actorIsFullAdmin()) {
            return true;
        }

        if ($this->holdsAdminRole($target)) {
            return false;
        }

        $outside = array_diff(array_keys($this->permissions->membershipPermissions($target)), $this->manageablePermissions());

        return $outside === [];
    }

    /** @throws DomainException */
    public function assertCanActOn(OrganizationMembership $target): void
    {
        if (! $this->canActOn($target)) {
            $this->refuseEscalation(
                'That person has more access than you do, so only an administrator can change their access.',
                ['rule' => 'target_outranks_actor', 'member' => $target->displayName()],
            );
        }
    }

    /**
     * @param  iterable<Role>  $roles  roles being assigned or removed
     *
     * @throws DomainException
     */
    public function assertCanManageRoles(iterable $roles, string $field = 'roles'): void
    {
        $roles = collect($roles);
        $allowed = array_flip($this->manageableRoleIds($roles));

        $refused = $roles->reject(fn (Role $role) => isset($allowed[$role->id]));

        if ($refused->isNotEmpty()) {
            $this->refuseEscalation(
                'You can only assign roles whose permissions you hold yourself.',
                ['rule' => 'role_exceeds_actor', 'roles' => $refused->pluck('name')->values()->all()],
                $field,
            );
        }
    }

    /**
     * @param  list<string>  $keys  permissions being granted or revoked
     *
     * @throws DomainException
     */
    public function assertCanChangePermissions(array $keys, string $field = 'permissions'): void
    {
        $outside = array_values(array_diff($keys, $this->manageablePermissions()));

        if ($outside !== []) {
            $this->refuseEscalation(
                'You can only grant or remove permissions that you hold yourself.',
                ['rule' => 'permission_exceeds_actor', 'permissions' => $outside],
                $field,
            );
        }
    }

    /** Whether any other active member holds the Organization Administrator role. */
    public function anotherActiveAdminExists(OrganizationMembership $except): bool
    {
        return DB::table('membership_roles')
            ->join('roles', 'roles.id', '=', 'membership_roles.role_id')
            ->join('organization_memberships as m', 'm.id', '=', 'membership_roles.membership_id')
            ->where('membership_roles.organization_id', $except->organization_id)
            ->where('roles.organization_id', $except->organization_id)
            ->where('roles.key', RoleTemplates::ORG_ADMIN)
            ->where('m.status', MembershipStatus::Active->value)
            ->where('m.id', '!=', $except->id)
            ->exists();
    }

    /**
     * Call inside the transaction, after lockOrganization(): the lock is what makes two
     * administrators demoting each other at the same instant impossible.
     *
     * @throws DomainException
     */
    public function assertKeepsAnAdministrator(OrganizationMembership $leaving): void
    {
        if ($leaving->isActive() && $this->holdsAdminRole($leaving) && ! $this->anotherActiveAdminExists($leaving)) {
            throw new DomainException(
                'An organization must always have at least one active administrator. Make someone else an administrator first.',
                'last_admin',
            );
        }
    }

    /** Serialises writers of access (roles, status, invitations) on the organization row. Use inside a transaction. */
    public function lockOrganization(): Organization
    {
        return Organization::query()->whereKey($this->organization()->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $detail
     *
     * @throws DomainException
     */
    private function refuseEscalation(string $message, array $detail, ?string $field = null): never
    {
        $this->audit->record(
            'security.privilege_escalation_blocked',
            subject: $this->actor(),
            metadata: $detail,
            summary: $message,
        );

        throw new DomainException($message, 'privilege_escalation', $field);
    }
}
