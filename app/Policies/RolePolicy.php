<?php

namespace App\Policies;

use App\Domain\Identity\PermissionResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * "May this person manage this role?" for routes and screens (what to offer, what to hide).
 * Roles of another organization and platform roles are answered 404, so their existence is not
 * revealed; a missing permission or a protected role is a 403 with the reason.
 *
 * These answers only decide what a caller offers. The role actions (CreateRole,
 * UpdateRolePermissions, DeleteRole) enforce the same rules, plus the privilege-escalation
 * rules of AccessGuard, themselves, so skipping this policy never skips a rule.
 */
final class RolePolicy
{
    private const PERMISSION = 'roles.manage';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PermissionResolver $permissions,
    ) {}

    /** The roles list and the create form. */
    public function viewAny(User $user): Response
    {
        return $this->allowed($user) ? Response::allow() : $this->refusal($user);
    }

    public function view(User $user, Role $role): Response
    {
        return $this->visible($user, $role) ?? Response::allow();
    }

    public function create(User $user): Response
    {
        return $this->viewAny($user);
    }

    /** Name, description and permissions. Locked roles (the Organization Administrator) are read-only. */
    public function update(User $user, Role $role): Response
    {
        return $this->visible($user, $role)
            ?? ($role->is_locked
                ? Response::deny("{$role->name} is locked: it always holds every permission and cannot be edited.")
                : Response::allow());
    }

    /** Built-in (system) roles can be edited but never deleted. Whether anyone still holds the role is checked by DeleteRole. */
    public function delete(User $user, Role $role): Response
    {
        return $this->visible($user, $role)
            ?? (($role->is_locked || $role->is_system)
                ? Response::deny("{$role->name} is a built-in role and cannot be deleted.")
                : Response::allow());
    }

    /** Null when the person may work with this role at all; otherwise the refusal. */
    private function visible(User $user, Role $role): ?Response
    {
        if (! $this->allowed($user)) {
            return $this->refusal($user);
        }

        $organizationId = $this->tenant->id();
        $roleOrganization = $role->getAttributes()['organization_id'] ?? null;

        return ($organizationId !== null && $roleOrganization === $organizationId && $role->scope === Role::SCOPE_ORGANIZATION)
            ? null
            : Response::denyAsNotFound();
    }

    private function allowed(User $user): bool
    {
        $membership = $this->membership($user);

        return $membership !== null && $this->permissions->membershipHas($membership, self::PERMISSION);
    }

    private function refusal(User $user): Response
    {
        return $this->membership($user) === null ? Response::denyAsNotFound() : Response::deny();
    }

    /** The acting membership of the current request, only if it is this user's and active. */
    private function membership(User $user): ?OrganizationMembership
    {
        $membership = $this->tenant->membership();

        return ($membership !== null && $membership->user_id === $user->id && $membership->isActive()) ? $membership : null;
    }
}
