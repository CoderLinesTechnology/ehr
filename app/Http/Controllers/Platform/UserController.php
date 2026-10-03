<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Identity\MembershipStatus;
use App\Domain\Platform\Users\DisableUser;
use App\Domain\Platform\Users\EnableUser;
use App\Domain\Platform\Users\ResendVerification;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Platform\Concerns\ReadsListQuery;
use App\Http\Requests\Platform\DisableUserRequest;
use App\Http\Requests\Platform\EnableUserRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Accounts across all organizations: who they are, whether they can sign in,
 * and which organizations (by NAME, status and role names) they belong to.
 * Nothing about what they do inside an organization.
 */
final class UserController extends Controller
{
    use ReadsListQuery;

    private const SORTS = ['name', 'email', 'created_at', 'last_login_at'];

    private const PER_PAGE = 25;

    public function index(Request $request): View
    {
        Gate::authorize('platform.users.view');

        $term = $this->queryText($request, 'q');
        $status = $this->queryChoice($request, 'status', ['active', 'disabled']);
        $staffOnly = $request->query('staff') === '1';
        $sort = $this->sortColumn($request, self::SORTS, 'created_at');
        $direction = $this->sortDirection($request, in_array($sort, ['name', 'email'], true) ? 'asc' : 'desc');

        $users = User::query()
            // The list never needs credentials or two-factor material, so they are never loaded.
            ->select(['users.id', 'users.name', 'users.email', 'users.status', 'users.email_verified_at', 'users.last_login_at', 'users.created_at'])
            ->addSelect(['organizations_count' => DB::table('organization_memberships')
                ->selectRaw('count(*)')
                ->whereColumn('organization_memberships.user_id', 'users.id')
                ->where('organization_memberships.status', MembershipStatus::Active->value)])
            ->with('platformRoles:id,key,name')
            ->when($term !== '', function ($query) use ($term) {
                $like = $this->containsPattern($term);
                $query->where(fn ($match) => $match->where('users.name', 'ilike', $like)->orWhere('users.email', 'ilike', $like));
            })
            ->when($status !== null, fn ($query) => $query->where('users.status', $status))
            ->when($staffOnly, fn ($query) => $query->whereExists(fn ($exists) => $exists
                ->selectRaw('1')->from('platform_user_roles')->whereColumn('platform_user_roles.user_id', 'users.id')))
            ->when(
                $sort === 'last_login_at',
                // Never-signed-in accounts go last whichever way the list is sorted.
                fn ($query) => $query->orderByRaw('users.last_login_at '.($direction === 'asc' ? 'ASC' : 'DESC').' NULLS LAST'),
                fn ($query) => $query->orderBy('users.'.$sort, $direction),
            )
            ->orderBy('users.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('platform.users.index', [
            'users' => $users,
            'filters' => ['q' => $term, 'status' => $status, 'staff' => $staffOnly],
            'timezone' => $request->user()->timezone,
        ]);
    }

    public function show(Request $request, User $user): View
    {
        Gate::authorize('platform.users.view');

        // Organization NAME, status and role names: one query, no tenant-scoped model involved.
        $memberships = DB::table('organization_memberships AS m')
            ->join('organizations AS o', 'o.id', '=', 'm.organization_id')
            ->leftJoin('membership_roles AS mr', fn ($join) => $join
                ->on('mr.membership_id', '=', 'm.id')
                ->on('mr.organization_id', '=', 'm.organization_id'))
            ->leftJoin('roles AS r', 'r.id', '=', 'mr.role_id')
            ->where('m.user_id', $user->id)
            ->orderBy('o.name')
            ->orderBy('r.name')
            ->limit(200)
            ->get(['m.id AS membership_id', 'm.status AS membership_status', 'o.name AS organization_name', 'o.slug AS organization_slug', 'o.status AS organization_status', 'r.name AS role_name'])
            ->groupBy('membership_id')
            ->map(fn ($rows) => [
                'organization' => $rows->first()->organization_name,
                'slug' => $rows->first()->organization_slug,
                'organization_status' => $rows->first()->organization_status,
                'status' => $rows->first()->membership_status,
                'roles' => $rows->pluck('role_name')->filter()->values()->all(),
            ])
            ->values();

        return view('platform.users.show', [
            'account' => $user,
            'memberships' => $memberships,
            'platformRoles' => $user->platformRoles()->orderBy('roles.name')->get(['roles.id', 'roles.key', 'roles.name']),
            'audit' => AuditLog::query()
                ->platformVisible()
                ->where('subject_type', 'user')
                ->where('subject_id', $user->id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
            'timezone' => $request->user()->timezone,
        ]);
    }

    public function disable(DisableUserRequest $request, User $user, DisableUser $disable): RedirectResponse
    {
        Gate::authorize('platform.admins.manage');

        $disable($user, $request->user(), $request->validated('reason'));

        return redirect()->route('platform.users.show', $user)->with('success', "{$user->name}'s account was disabled and signed out.");
    }

    public function enable(EnableUserRequest $request, User $user, EnableUser $enable): RedirectResponse
    {
        Gate::authorize('platform.admins.manage');

        $enable($user, $request->user(), $request->validated('reason'));

        return redirect()->route('platform.users.show', $user)->with('success', "{$user->name}'s account was enabled.");
    }

    public function verification(User $user, ResendVerification $resend): RedirectResponse
    {
        Gate::authorize('platform.support.act');

        $resend($user);

        return redirect()->route('platform.users.show', $user)->with('success', 'A new verification email was sent.');
    }
}
