<?php

namespace App\Domain\Platform\Queries;

use App\Domain\Identity\MembershipStatus;
use App\Domain\Platform\Search;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Accounts as the platform sees them: the list, one account's overview, and the
 * people who hold platform roles. Credentials, two-factor secrets and recovery
 * codes are never selected: the queries name the columns they need.
 */
final class PlatformUserQuery
{
    public const SORTS = ['name', 'email', 'created_at', 'last_login_at'];

    public const PER_PAGE = 25;

    /** Caps for the lists on a single account's page and on the administrators page. */
    private const MEMBERSHIP_ROWS = 200;

    private const ADMINISTRATORS = 200;

    private const AUDIT_LIMIT = 10;

    /**
     * Filters: q (name or email), status (active|disabled), staff (truthy: only holders of a platform role), sort, direction.
     *
     * @param  array{q?: mixed, status?: mixed, staff?: mixed, sort?: mixed, direction?: mixed}  $filters
     * @return LengthAwarePaginator<int, PlatformUserListItem>
     */
    public function paginate(array $filters = [], int $perPage = self::PER_PAGE, ?int $page = null): LengthAwarePaginator
    {
        $term = Search::clean($filters['q'] ?? null);
        $status = $this->choice($filters['status'] ?? null, ['active', 'disabled']);
        $staffOnly = in_array($filters['staff'] ?? null, [true, 1, '1', 'true', 'on'], true);
        $sort = $this->choice($filters['sort'] ?? null, self::SORTS) ?? 'created_at';
        $direction = $this->choice($filters['direction'] ?? null, ['asc', 'desc']) ?? (in_array($sort, ['name', 'email'], true) ? 'asc' : 'desc');

        $users = User::query()
            ->select(['users.id', 'users.name', 'users.email', 'users.status', 'users.email_verified_at', 'users.last_login_at', 'users.created_at'])
            ->addSelect(['organizations_count' => DB::table('organization_memberships')
                ->selectRaw('count(*)')
                ->whereColumn('organization_memberships.user_id', 'users.id')
                ->where('organization_memberships.status', MembershipStatus::Active->value)])
            ->with('platformRoles:id,key,name')
            ->when($term !== '', function ($query) use ($term) {
                $like = Search::contains($term);
                $query->where(fn ($match) => $match->where('users.name', 'ilike', $like)->orWhere('users.email', 'ilike', $like));
            })
            ->when($status !== null, fn ($query) => $query->where('users.status', $status))
            ->when($staffOnly, fn ($query) => $query->whereExists(fn ($exists) => $exists
                ->selectRaw('1')->from('platform_user_roles')->whereColumn('platform_user_roles.user_id', 'users.id')))
            ->when(
                $sort === 'last_login_at',
                // Accounts that never signed in go last whichever way the list is sorted.
                fn ($query) => $query->orderByRaw('users.last_login_at '.($direction === 'asc' ? 'ASC' : 'DESC').' NULLS LAST'),
                fn ($query) => $query->orderBy('users.'.$sort, $direction),
            )
            ->orderBy('users.id')
            ->paginate(max(1, min($perPage, 100)), ['*'], 'page', $page);

        return $users->through(fn (User $user) => new PlatformUserListItem(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            disabled: $user->isDisabled(),
            emailVerified: $user->email_verified_at !== null,
            organizationsCount: (int) $user->organizations_count,
            platformRoles: $user->platformRoles->pluck('name')->sort()->values()->all(),
            lastLoginAt: $user->last_login_at,
            createdAt: $user->created_at,
        ));
    }

    public function overview(User $user): PlatformUserOverview
    {
        // Organization NAME, status and role names in one statement, without any tenant-scoped model.
        $memberships = DB::table('organization_memberships AS m')
            ->join('organizations AS o', 'o.id', '=', 'm.organization_id')
            ->leftJoin('membership_roles AS mr', fn ($join) => $join
                ->on('mr.membership_id', '=', 'm.id')
                ->on('mr.organization_id', '=', 'm.organization_id'))
            ->leftJoin('roles AS r', 'r.id', '=', 'mr.role_id')
            ->where('m.user_id', $user->id)
            ->orderBy('o.name')
            ->orderBy('m.id')
            ->orderBy('r.name')
            ->limit(self::MEMBERSHIP_ROWS)
            ->get(['m.id AS membership_id', 'm.status AS membership_status', 'o.name AS organization_name', 'o.slug AS organization_slug', 'o.status AS organization_status', 'r.name AS role_name'])
            ->groupBy('membership_id')
            ->map(fn (Collection $rows) => [
                'organization' => $rows->first()->organization_name,
                'slug' => $rows->first()->organization_slug,
                'organization_status' => $rows->first()->organization_status,
                'status' => $rows->first()->membership_status,
                'roles' => $rows->pluck('role_name')->filter()->values()->all(),
            ])
            ->values()
            ->all();

        $platformRoles = $user->platformRoles()
            ->orderBy('roles.name')
            ->get(['roles.id', 'roles.key', 'roles.name'])
            ->map(fn ($role) => ['key' => $role->key, 'name' => $role->name])
            ->all();

        return new PlatformUserOverview(
            profile: [
                'name' => $user->name,
                'email' => $user->email,
                'disabled' => $user->isDisabled(),
                'email_verified_at' => $user->email_verified_at,
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'timezone' => $user->timezone,
                'last_login_at' => $user->last_login_at,
                'created_at' => $user->created_at,
            ],
            memberships: $memberships,
            platformRoles: $platformRoles,
            recentAudit: AuditLog::query()
                ->platformVisible()
                ->where('subject_type', 'user')
                ->where('subject_id', $user->id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(self::AUDIT_LIMIT)
                ->get(),
        );
    }

    /**
     * Everyone who holds a platform role, with each role, when it was granted
     * and by whom (a name). Two queries however many administrators there are.
     *
     * @return Collection<int, PlatformAdministrator>
     */
    public function administrators(): Collection
    {
        $admins = User::query()
            ->select(['users.id', 'users.name', 'users.email', 'users.status', 'users.two_factor_confirmed_at', 'users.last_login_at'])
            ->whereExists(fn ($exists) => $exists
                ->selectRaw('1')->from('platform_user_roles')->whereColumn('platform_user_roles.user_id', 'users.id'))
            ->with('platformRoles:id,key,name')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->limit(self::ADMINISTRATORS)
            ->get();

        $grantorIds = $admins
            ->flatMap(fn (User $admin) => $admin->platformRoles->pluck('pivot.granted_by_user_id'))
            ->filter()->unique()->values()->all();
        $grantors = $grantorIds === [] ? [] : User::query()->whereIn('id', $grantorIds)->pluck('name', 'id')->all();

        return $admins->map(fn (User $admin) => new PlatformAdministrator(
            id: $admin->id,
            name: $admin->name,
            email: $admin->email,
            disabled: $admin->isDisabled(),
            twoFactorConfirmed: $admin->two_factor_confirmed_at !== null,
            lastLoginAt: $admin->last_login_at,
            roles: $admin->platformRoles->sortBy('name')->map(fn ($role) => [
                'key' => $role->key,
                'name' => $role->name,
                'granted_at' => $role->pivot->granted_at !== null ? CarbonImmutable::parse($role->pivot->granted_at) : null,
                'granted_by' => $grantors[$role->pivot->granted_by_user_id] ?? null,
            ])->values()->all(),
        ));
    }

    /** @param list<string> $allowed */
    private function choice(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }
}
