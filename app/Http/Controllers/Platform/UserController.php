<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Platform\PlatformAbility;
use App\Domain\Platform\Queries\PlatformUserQuery;
use App\Domain\Platform\Users\DisableUser;
use App\Domain\Platform\Users\EnableUser;
use App\Domain\Platform\Users\ResendVerification;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Platform\Concerns\AuthorizesPlatform;
use App\Http\Requests\Platform\DisableUserRequest;
use App\Http\Requests\Platform\EnableUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Accounts across all organizations: who they are, whether they can sign in, and which
 * organizations (by NAME, status and role names) they belong to. Nothing about what they do
 * inside an organization.
 */
final class UserController extends Controller
{
    use AuthorizesPlatform;

    public function index(Request $request, PlatformUserQuery $query): View
    {
        $this->allow(PlatformAbility::ViewUsers);

        $filters = $request->only(['q', 'status', 'staff', 'sort', 'direction']);

        return view('platform.users.index', [
            'users' => $query->paginate($filters)->withQueryString(),
            'filters' => [
                'q' => is_string($filters['q'] ?? null) ? $filters['q'] : '',
                'status' => $filters['status'] ?? null,
                'staff' => ($filters['staff'] ?? null) === '1',
            ],
            'timezone' => $request->user()->timezone,
        ]);
    }

    public function show(Request $request, User $user, PlatformUserQuery $query): View
    {
        $this->allow(PlatformAbility::ViewUsers);

        return view('platform.users.show', [
            'account' => $user,
            'overview' => $query->overview($user),
            'isSelf' => $user->is($request->user()),
            'timezone' => $request->user()->timezone,
        ]);
    }

    public function disable(DisableUserRequest $request, User $user, DisableUser $disable): RedirectResponse
    {
        $this->allow(PlatformAbility::DisableUser);

        $disable($user, $request->user(), $request->validated('reason'));

        return redirect()->route('platform.users.show', $user)->with('success', "{$user->name}'s account was disabled and signed out.");
    }

    public function enable(EnableUserRequest $request, User $user, EnableUser $enable): RedirectResponse
    {
        $this->allow(PlatformAbility::EnableUser);

        $enable($user, $request->user(), $request->validated('reason'));

        return redirect()->route('platform.users.show', $user)->with('success', "{$user->name}'s account was enabled.");
    }

    public function verification(User $user, ResendVerification $resend): RedirectResponse
    {
        $this->allow(PlatformAbility::ResendVerification);

        $resend($user);

        return redirect()->route('platform.users.show', $user)->with('success', 'A new verification email was sent.');
    }
}
