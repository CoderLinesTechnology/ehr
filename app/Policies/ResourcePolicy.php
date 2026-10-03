<?php

namespace App\Policies;

use App\Domain\Identity\PermissionResolver;
use App\Domain\Resources\ResourceAudience;
use App\Domain\Resources\ResourceStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\OrganizationMembership;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Record-level rules for resources (the permission keys themselves are answered by Gate::before).
 *
 *   403  the member lacks the permission altogether (they know the module exists)
 *   404  the resource is outside what they may see: another status or audience (existence is not revealed)
 *
 * A viewer (resources.view) sees published resources for staff or everyone; a manager (resources.manage)
 * sees every resource of the organization. Another organization's resource never reaches this point:
 * the tenant scope on the model makes it a 404 at route binding.
 */
final class ResourcePolicy
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PermissionResolver $permissions,
    ) {}

    public function viewAny(User $user): Response
    {
        $membership = $this->membership($user);

        return match (true) {
            $membership === null => Response::denyAsNotFound(),
            $this->has($membership, 'resources.view') => Response::allow(),
            default => Response::deny(),
        };
    }

    public function view(User $user, Resource $resource): Response
    {
        $membership = $this->membership($user);
        if ($membership === null) {
            return Response::denyAsNotFound();
        }
        if (! $this->has($membership, 'resources.view')) {
            return Response::deny();
        }

        return $this->canSee($membership, $resource) ? Response::allow() : Response::denyAsNotFound();
    }

    /** The attached PDF: whoever may see the resource, when it has one. */
    public function file(User $user, Resource $resource): Response
    {
        $seen = $this->view($user, $resource);

        return $seen->allowed() && $resource->hasFile() ? Response::allow() : ($seen->allowed() ? Response::denyAsNotFound() : $seen);
    }

    public function create(User $user): Response
    {
        return $this->manage($user);
    }

    public function update(User $user, Resource $resource): Response
    {
        return $this->manage($user);
    }

    public function publish(User $user, Resource $resource): Response
    {
        return $this->manage($user);
    }

    public function archive(User $user, Resource $resource): Response
    {
        return $this->manage($user);
    }

    private function manage(User $user): Response
    {
        $membership = $this->membership($user);

        return match (true) {
            $membership === null => Response::denyAsNotFound(),
            $this->has($membership, 'resources.manage') => Response::allow(),
            default => Response::deny(),
        };
    }

    private function canSee(OrganizationMembership $membership, Resource $resource): bool
    {
        if ($this->has($membership, 'resources.manage')) {
            return true;
        }

        return $resource->status === ResourceStatus::Published
            && in_array($resource->audience, [ResourceAudience::Staff, ResourceAudience::Everyone], true);
    }

    private function has(OrganizationMembership $membership, string $permission): bool
    {
        return $this->permissions->membershipHas($membership, $permission);
    }

    private function membership(User $user): ?OrganizationMembership
    {
        $membership = $this->tenant->membership();

        return ($membership !== null && $membership->user_id === $user->id && $membership->isActive()) ? $membership : null;
    }
}
