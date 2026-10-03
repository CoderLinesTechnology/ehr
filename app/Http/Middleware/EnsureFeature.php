<?php

namespace App\Http\Middleware;

use App\Domain\Saas\EntitlementService;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `feature:<key>` — the organization's plan (or an override) must include the module. */
final class EnsureFeature
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $organization = $this->tenant->organizationOrFail();

        if (! $this->entitlements->allows($organization, $feature)) {
            return response()->view('tenancy.feature-unavailable', ['feature' => $feature], 403);
        }

        return $next($request);
    }
}
