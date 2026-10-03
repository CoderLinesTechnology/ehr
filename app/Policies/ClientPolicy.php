<?php

namespace App\Policies;

use App\Domain\Clients\ClientStatus;
use App\Domain\Clients\ClientVisibility;
use App\Domain\Identity\PermissionResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\Client;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Record-level rules for clients (the permission keys themselves are answered
 * by Gate::before). The two ways to say no are different on purpose:
 *
 *   403  the member lacks the permission altogether (they know the feature exists)
 *   404  the client is outside what they may see (existence is not revealed)
 *
 * Visibility is ClientVisibility, the same rule the list and search apply.
 */
final class ClientPolicy
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PermissionResolver $permissions,
    ) {}

    /** The client list and global search. */
    public function viewAny(User $user): Response
    {
        $membership = $this->membership($user);

        if ($membership === null) {
            return Response::denyAsNotFound();
        }

        return ClientVisibility::hasAnyAccess($membership) ? Response::allow() : Response::deny();
    }

    /** The profile and all of its tabs. */
    public function view(User $user, Client $client): Response
    {
        $membership = $this->membership($user);

        if ($membership === null) {
            return Response::denyAsNotFound();
        }

        if (! ClientVisibility::hasAnyAccess($membership)) {
            return Response::deny();
        }

        return ClientVisibility::allows($client, $membership) ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): Response
    {
        $membership = $this->membership($user);

        if ($membership === null) {
            return Response::denyAsNotFound();
        }

        return $this->permissions->membershipHas($membership, 'clients.create') ? Response::allow() : Response::deny();
    }

    /** Demographics and contacts (clients.edit) of a client the member can see. */
    public function update(User $user, Client $client): Response
    {
        return $this->visibleWith($user, $client, 'clients.edit');
    }

    /** Archive and restore (clients.archive) of a client the member can see. */
    public function archive(User $user, Client $client): Response
    {
        return $this->visibleWith($user, $client, 'clients.archive');
    }

    /**
     * The check callers of ChangeClientStatus make first: moving a client to
     * or from archived needs `clients.archive`; every other status change
     * (active ⇄ inactive) needs `clients.edit`. Usage:
     * Gate::authorize('changeStatus', [$client, ClientStatus::Archived]).
     */
    public function changeStatus(User $user, Client $client, ClientStatus $to): Response
    {
        return ($to === ClientStatus::Archived || $client->status === ClientStatus::Archived)
            ? $this->archive($user, $client)
            : $this->update($user, $client);
    }

    private function visibleWith(User $user, Client $client, string $permission): Response
    {
        $membership = $this->membership($user);

        if ($membership === null) {
            return Response::denyAsNotFound();
        }

        if (! $this->permissions->membershipHas($membership, $permission)) {
            // Someone who cannot even see the client must not learn it exists.
            return ClientVisibility::allows($client, $membership) ? Response::deny() : Response::denyAsNotFound();
        }

        return ClientVisibility::allows($client, $membership) ? Response::allow() : Response::denyAsNotFound();
    }

    /** The acting membership of the current request, only if it is this user's and active. */
    private function membership(User $user): ?OrganizationMembership
    {
        $membership = $this->tenant->membership();

        return ($membership !== null && $membership->user_id === $user->id && $membership->isActive()) ? $membership : null;
    }
}
