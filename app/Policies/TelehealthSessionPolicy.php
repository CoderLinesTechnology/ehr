<?php

namespace App\Policies;

use App\Domain\Identity\PermissionResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\OrganizationMembership;
use App\Models\TelehealthSession;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Record-level rules for telehealth sessions (permission keys themselves are answered by Gate::before).
 *
 *   403  the member lacks the permission altogether
 *   404  the session is outside what they may see (its existence is not revealed)
 *
 * Visibility: `appointments.view_all` sees every session; everyone else only the sessions where they are the
 * clinician. Clinical content (notes, recordings, transcripts) needs more: the session's own clinician, or
 * `telehealth.notes`, on a session they can see.
 */
final class TelehealthSessionPolicy
{
    private const ENTRY = ['telehealth.join', 'telehealth.notes', 'telehealth.manage'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PermissionResolver $permissions,
    ) {}

    /** The sessions list and the device check. */
    public function viewAny(User $user): Response
    {
        $membership = $this->membership($user);
        if ($membership === null) {
            return Response::denyAsNotFound();
        }

        return $this->hasAny($membership, self::ENTRY) ? Response::allow() : Response::deny();
    }

    public function view(User $user, TelehealthSession $session): Response
    {
        $membership = $this->membership($user);
        if ($membership === null || ! $this->sees($session, $membership)) {
            return Response::denyAsNotFound();
        }

        return $this->hasAny($membership, self::ENTRY) ? Response::allow() : Response::deny();
    }

    /** Open the join page, start, end, supply the meeting link. */
    public function join(User $user, TelehealthSession $session): Response
    {
        $membership = $this->membership($user);
        if ($membership === null || ! $this->sees($session, $membership)) {
            return Response::denyAsNotFound();
        }

        return $this->permissions->membershipHas($membership, 'telehealth.join') ? Response::allow() : Response::deny();
    }

    /** Notes, consent, recordings, transcripts: the session's own clinician, or `telehealth.notes`. */
    public function clinical(User $user, TelehealthSession $session): Response
    {
        $membership = $this->membership($user);
        if ($membership === null || ! $this->sees($session, $membership)) {
            return Response::denyAsNotFound();
        }

        return $this->clinicalAllowed($session, $membership) ? Response::allow() : Response::deny();
    }

    private function clinicalAllowed(TelehealthSession $session, OrganizationMembership $membership): bool
    {
        return $session->clinician_membership_id === $membership->id
            || $this->permissions->membershipHas($membership, 'telehealth.notes');
    }

    private function sees(TelehealthSession $session, OrganizationMembership $membership): bool
    {
        if ($session->organization_id !== $membership->organization_id) {
            return false;
        }

        return $session->clinician_membership_id === $membership->id
            || $this->permissions->membershipHas($membership, 'appointments.view_all');
    }

    /** @param list<string> $permissions */
    private function hasAny(OrganizationMembership $membership, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->permissions->membershipHas($membership, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function membership(User $user): ?OrganizationMembership
    {
        $membership = $this->tenant->membership();

        return ($membership !== null && $membership->user_id === $user->id && $membership->isActive()) ? $membership : null;
    }
}
