<?php

namespace App\Policies;

use App\Domain\Identity\PermissionResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\Appointment;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Record-level rules for appointments (permission keys themselves are answered by Gate::before).
 *
 *   403  the member lacks the permission altogether
 *   404  the appointment is outside what they may see (existence is not revealed)
 *
 * Visibility: `appointments.view_all` sees every appointment; `appointments.view` only the ones
 * where the member is the clinician. Acting on an appointment (edit, cancel) additionally needs
 * that appointment to be visible.
 */
final class AppointmentPolicy
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PermissionResolver $permissions,
    ) {}

    /** The calendar page. */
    public function viewAny(User $user): Response
    {
        $membership = $this->membership($user);
        if ($membership === null) {
            return Response::denyAsNotFound();
        }

        return $this->has($membership, 'appointments.view_all') || $this->has($membership, 'appointments.view')
            ? Response::allow() : Response::deny();
    }

    public function view(User $user, Appointment $appointment): Response
    {
        $membership = $this->membership($user);
        if ($membership === null) {
            return Response::denyAsNotFound();
        }

        return $this->sees($appointment, $membership) ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): Response
    {
        return $this->permitted($user, 'appointments.create');
    }

    /** Details, reschedule and the non-cancelling status changes (check in, complete…). */
    public function update(User $user, Appointment $appointment): Response
    {
        return $this->visibleWith($user, $appointment, 'appointments.edit');
    }

    /** Cancel and mark no-show. */
    public function cancel(User $user, Appointment $appointment): Response
    {
        return $this->visibleWith($user, $appointment, 'appointments.cancel');
    }

    /** Knowingly double-book (permission only; there is no record yet). */
    public function overbook(User $user): Response
    {
        return $this->permitted($user, 'appointments.overbook');
    }

    private function permitted(User $user, string $permission): Response
    {
        $membership = $this->membership($user);
        if ($membership === null) {
            return Response::denyAsNotFound();
        }

        return $this->has($membership, $permission) ? Response::allow() : Response::deny();
    }

    private function visibleWith(User $user, Appointment $appointment, string $permission): Response
    {
        $membership = $this->membership($user);
        if ($membership === null || ! $this->sees($appointment, $membership)) {
            return Response::denyAsNotFound();
        }

        return $this->has($membership, $permission) ? Response::allow() : Response::deny();
    }

    private function sees(Appointment $appointment, OrganizationMembership $membership): bool
    {
        if ($appointment->organization_id !== $membership->organization_id) {
            return false;
        }

        return $this->has($membership, 'appointments.view_all')
            || ($this->has($membership, 'appointments.view') && $appointment->clinician_membership_id === $membership->id);
    }

    private function has(OrganizationMembership $membership, string $permission): bool
    {
        return $this->permissions->membershipHas($membership, $permission);
    }

    /** The acting membership of the current request, only if it is this user's and active. */
    private function membership(User $user): ?OrganizationMembership
    {
        $membership = $this->tenant->membership();

        return ($membership !== null && $membership->user_id === $user->id && $membership->isActive()) ? $membership : null;
    }
}
