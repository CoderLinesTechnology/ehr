<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Identity\PlatformRoles\GrantPlatformRole;
use App\Domain\Identity\PlatformRoles\RevokePlatformRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\GrantPlatformRoleRequest;
use App\Http\Requests\Platform\RevokePlatformRoleRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Who holds platform roles: grant a role to an existing account by email, or take it away. */
final class AdminController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('platform.admins.manage');

        $admins = User::query()
            ->select(['users.id', 'users.name', 'users.email', 'users.status', 'users.two_factor_confirmed_at', 'users.last_login_at'])
            ->whereExists(fn ($exists) => $exists
                ->selectRaw('1')->from('platform_user_roles')->whereColumn('platform_user_roles.user_id', 'users.id'))
            ->with('platformRoles:id,key,name')
            ->orderBy('users.name')
            ->limit(200)
            ->get();

        // Who granted each role: one lookup for the page, names only.
        $grantorIds = $admins->flatMap(fn (User $admin) => $admin->platformRoles->pluck('pivot.granted_by_user_id'))->filter()->unique()->values()->all();
        $grantors = $grantorIds === [] ? [] : User::query()->whereIn('id', $grantorIds)->pluck('name', 'id')->all();

        return view('platform.admins.index', [
            'admins' => $admins,
            'grantors' => $grantors,
            'roles' => Role::query()->platform()->orderBy('name')->get(['id', 'key', 'name', 'description']),
            'timezone' => $request->user()->timezone,
        ]);
    }

    public function store(GrantPlatformRoleRequest $request, GrantPlatformRole $grant): RedirectResponse
    {
        Gate::authorize('platform.admins.manage');

        $target = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($request->validated('email'))])->first();
        if ($target === null) {
            throw ValidationException::withMessages(['email' => 'No account uses that email address. They need to register and verify their email first.']);
        }

        $role = $grant($target, $request->validated('role'), $request->user(), $request->validated('reason'));

        return redirect()->route('platform.admins.index')
            ->with('success', "{$target->name} now has the {$role->name} role. They will be asked to set up two-factor authentication when they next open the console.");
    }

    public function destroy(RevokePlatformRoleRequest $request, User $user, string $role, RevokePlatformRole $revoke): RedirectResponse
    {
        Gate::authorize('platform.admins.manage');

        $revoke($user, $role, $request->user(), $request->validated('reason'));

        return redirect()->route('platform.admins.index')->with('success', "The role was removed from {$user->name}.");
    }
}
