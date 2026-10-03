<?php

namespace App\Http\Controllers;

use App\Domain\Identity\PermissionResolver;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class HomeController extends Controller
{
    public function root(Request $request): RedirectResponse
    {
        return $request->user() ? redirect()->route('home') : redirect()->route('login');
    }

    /**
     * Where a signed-in user belongs: their only organization, a chooser when
     * they have several, the platform console for platform-only staff, or
     * onboarding when they have no organization yet.
     */
    public function home(Request $request, PermissionResolver $permissions): RedirectResponse
    {
        $user = $request->user();
        $organizationIds = $user->activeMemberships()->pluck('organization_id');

        if ($organizationIds->count() === 1) {
            $organization = Organization::query()->find($organizationIds->first());

            return redirect()->route('app.dashboard', ['organization' => $organization->slug]);
        }

        if ($organizationIds->count() > 1) {
            return redirect()->route('organizations.choose');
        }

        if ($permissions->isPlatformUser($user)) {
            return redirect()->route('platform.dashboard');
        }

        return redirect()->route('onboarding.organization.create');
    }
}
