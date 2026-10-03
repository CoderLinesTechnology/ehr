<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves /o/{organization}/… to a tenant. Runs after authentication and
 * BEFORE route-model binding (see bootstrap/app.php priority), so every
 * {client}, {appointment}… binding is already confined to this organization.
 *
 * Unknown organization and "not a member" both answer 404, so URLs do not
 * reveal which organizations exist.
 */
final class ResolveTenant
{
    /** Session key: the organization the user last worked in (the account pages show its navigation). */
    public const LAST_ORGANIZATION_KEY = 'tenant.last_organization_id';

    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $parameter = $route->parameter('organization');
        $slug = $parameter instanceof Organization ? $parameter->slug : (string) $parameter;

        $organization = Organization::query()->where('slug', $slug)->first();
        abort_if($organization === null, 404);

        $membership = $this->tenant->bypass(fn () => OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->first());

        abort_if($membership === null || ! $membership->isActive(), 404);

        if (! $organization->allowsAccess()) {
            return response()->view('tenancy.unavailable', ['organization' => $organization], 403);
        }

        $this->tenant->set($organization, $membership);
        URL::defaults(['organization' => $organization->slug]);
        if ($request->hasSession() && $request->session()->get(self::LAST_ORGANIZATION_KEY) !== $organization->id) {
            $request->session()->put(self::LAST_ORGANIZATION_KEY, $organization->id);
        }

        // Controllers receive route parameters positionally: leaving
        // {organization} in place would hand show(Client $client) the
        // Organization. The tenant is read from TenantContext / tenant().
        $route->forgetParameter('organization');

        return $next($request);
    }
}
