<?php

namespace App\Policies;

use App\Domain\Identity\PermissionResolver;
use App\Domain\Programs\ProgramVisibility;
use App\Domain\Tenancy\TenantContext;
use App\Models\OrganizationMembership;
use App\Models\ProgramEnrollment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Record-level rules for one participant's enrollment:
 *
 *   404  the enrollment is outside what the member may see (a segmented program without programs.view_sud, or a
 *        client they may not see): existence is not revealed
 *   403  they can see it but lack programs.enroll to change it
 */
final class ProgramEnrollmentPolicy
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PermissionResolver $permissions,
    ) {}

    public function view(User $user, ProgramEnrollment $enrollment): Response
    {
        $membership = $this->membership($user);

        return match (true) {
            $membership === null => Response::denyAsNotFound(),
            ! $this->permissions->membershipHas($membership, 'programs.view') => Response::deny(),
            ProgramVisibility::allowsEnrollment($enrollment, $membership) => Response::allow(),
            default => Response::denyAsNotFound(),
        };
    }

    /** Change level, hold, resume, discharge, transfer. */
    public function manage(User $user, ProgramEnrollment $enrollment): Response
    {
        $seen = $this->view($user, $enrollment);
        if (! $seen->allowed()) {
            return $seen;
        }

        return $this->permissions->membershipHas($this->tenant->membership(), 'programs.enroll') ? Response::allow() : Response::deny();
    }

    private function membership(User $user): ?OrganizationMembership
    {
        $membership = $this->tenant->membership();

        return ($membership !== null && $membership->user_id === $user->id && $membership->isActive()) ? $membership : null;
    }
}
