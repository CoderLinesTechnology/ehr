<?php

namespace App\Policies;

use App\Domain\Identity\PermissionResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\OrganizationMembership;
use App\Models\Program;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Record-level rules for programs (the permission keys themselves are answered by Gate::before).
 *
 *   403  the member lacks the permission (they know the module exists)
 *   404  no active membership of this organization for this user
 *
 * Another organization's program never reaches this point: the tenant scope makes it a 404 at route binding.
 * A program flagged as substance-use treatment (42 CFR Part 2) can be viewed by anyone with programs.view (it is
 * an organizational fact), but its participants, and every action that touches them, need programs.view_sud: the
 * Program-level abilities `update`, `manage` and `admit` therefore ask for it, and ProgramEnrollmentPolicy
 * applies ProgramVisibility to each participant.
 */
final class ProgramPolicy
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PermissionResolver $permissions,
    ) {}

    public function viewAny(User $user): Response
    {
        return $this->require($user, 'programs.view');
    }

    public function view(User $user, Program $program): Response
    {
        return $this->require($user, 'programs.view');
    }

    public function create(User $user): Response
    {
        return $this->require($user, 'programs.manage');
    }

    /** Edit the program's own data (and its flag). */
    public function update(User $user, Program $program): Response
    {
        return $this->require($user, 'programs.manage', $program->is_sud_program ? 'programs.view_sud' : null);
    }

    /** Levels of care, staff, status and the schedule. */
    public function manage(User $user, Program $program): Response
    {
        return $this->require($user, 'programs.manage', $program->is_sud_program ? 'programs.view_sud' : null);
    }

    /** Admit clients, record attendance. */
    public function admit(User $user, Program $program): Response
    {
        return $this->require($user, 'programs.enroll', $program->is_sud_program ? 'programs.view_sud' : null);
    }

    private function require(User $user, ?string ...$permissions): Response
    {
        $membership = $this->membership($user);
        if ($membership === null) {
            return Response::denyAsNotFound();
        }

        foreach (array_filter($permissions) as $permission) {
            if (! $this->permissions->membershipHas($membership, $permission)) {
                return Response::deny();
            }
        }

        return Response::allow();
    }

    private function membership(User $user): ?OrganizationMembership
    {
        $membership = $this->tenant->membership();

        return ($membership !== null && $membership->user_id === $user->id && $membership->isActive()) ? $membership : null;
    }
}
