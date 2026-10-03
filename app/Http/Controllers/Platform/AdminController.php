<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Identity\PlatformRoles\GrantPlatformRole;
use App\Domain\Identity\PlatformRoles\RevokePlatformRole;
use App\Domain\Platform\PlatformAbility;
use App\Domain\Platform\Queries\PlatformUserQuery;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Platform\Concerns\AuthorizesPlatform;
use App\Http\Requests\Platform\GrantPlatformRoleRequest;
use App\Http\Requests\Platform\RevokePlatformRoleRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Who holds platform roles: grant a role to an existing account by email, or take it away. */
final class AdminController extends Controller
{
    use AuthorizesPlatform;

    public function index(Request $request, PlatformUserQuery $query): View
    {
        $this->allow(PlatformAbility::ViewAdministrators);

        return view('platform.admins.index', [
            'admins' => $query->administrators(),
            'roles' => Role::query()->platform()->orderBy('name')->get(['id', 'key', 'name', 'description']),
            'me' => $request->user()->id,
            'timezone' => $request->user()->timezone,
        ]);
    }

    public function store(GrantPlatformRoleRequest $request, GrantPlatformRole $grant): RedirectResponse
    {
        $this->allow(PlatformAbility::GrantPlatformRole);

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
        $this->allow(PlatformAbility::RevokePlatformRole);

        $revoke($user, $role, $request->user(), $request->validated('reason'));

        return redirect()->route('platform.admins.index')->with('success', "The role was removed from {$user->name}.");
    }
}
