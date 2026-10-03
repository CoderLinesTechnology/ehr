<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Saas\FeatureRegistry;
use App\Domain\Saas\RemoveEntitlementOverride;
use App\Domain\Saas\SetEntitlementOverride;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\RemoveEntitlementRequest;
use App\Http\Requests\Platform\SetEntitlementRequest;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** Per-organization overrides of plan features and limits. */
final class OrganizationEntitlementController extends Controller
{
    public function store(SetEntitlementRequest $request, Organization $organization, SetEntitlementOverride $set): RedirectResponse
    {
        Gate::authorize('platform.features.manage');

        $set(
            $organization,
            $request->featureKey(),
            $request->enabledValue(),
            $request->limitValue(),
            $request->unlimited(),
            $request->validated('reason'),
            $request->expiresAt(),
            $request->user(),
        );

        return redirect()->route('platform.organizations.show', $organization)
            ->with('success', 'The override was saved.');
    }

    public function destroy(RemoveEntitlementRequest $request, Organization $organization, string $feature, RemoveEntitlementOverride $remove): RedirectResponse
    {
        Gate::authorize('platform.features.manage');

        // An unknown key in the URL is a 404, not a domain error.
        abort_unless(in_array($feature, FeatureRegistry::keys(), true), 404);

        $remove($organization, $feature, $request->user(), $request->validated('reason'));

        return redirect()->route('platform.organizations.show', $organization)
            ->with('success', 'The override was removed. The plan\'s own value applies again.');
    }
}
