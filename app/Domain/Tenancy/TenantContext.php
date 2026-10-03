<?php

namespace App\Domain\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use Closure;

/**
 * The organization (and the acting membership) the current request or job
 * works inside. Bound as a scoped singleton: reset per request and per job.
 *
 * Tenant-scoped models refuse to query without it (fail closed). Code that
 * legitimately works across tenants must say so with bypass(), which is
 * deliberately easy to grep for in review.
 */
final class TenantContext
{
    private ?Organization $organization = null;

    private ?OrganizationMembership $membership = null;

    private int $bypassDepth = 0;

    public function set(Organization $organization, ?OrganizationMembership $membership = null): void
    {
        if ($membership !== null && $membership->organization_id !== $organization->id) {
            throw new TenantMismatch('Membership does not belong to the organization being set.');
        }

        $this->organization = $organization;
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->organization = null;
        $this->membership = null;
    }

    public function has(): bool
    {
        return $this->organization !== null;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function organizationOrFail(): Organization
    {
        return $this->organization ?? throw new MissingTenantContext;
    }

    public function id(): ?string
    {
        return $this->organization?->id;
    }

    public function membership(): ?OrganizationMembership
    {
        return $this->membership;
    }

    public function bypassing(): bool
    {
        return $this->bypassDepth > 0;
    }

    /**
     * Run $callback with tenant scoping disabled (platform analytics, the
     * tenant resolver itself). Never use it to read clinical content.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function bypass(Closure $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    /**
     * Run $callback inside $organization (jobs, seeders, onboarding), then
     * restore whatever context was active before.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Organization $organization, Closure $callback, ?OrganizationMembership $membership = null): mixed
    {
        [$previousOrganization, $previousMembership, $previousBypass] = [$this->organization, $this->membership, $this->bypassDepth];

        $this->set($organization, $membership);
        $this->bypassDepth = 0;

        try {
            return $callback();
        } finally {
            $this->organization = $previousOrganization;
            $this->membership = $previousMembership;
            $this->bypassDepth = $previousBypass;
        }
    }
}
