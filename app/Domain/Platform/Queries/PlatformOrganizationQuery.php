<?php

namespace App\Domain\Platform\Queries;

use App\Domain\Platform\OrganizationStatus;
use App\Domain\Platform\OrganizationUsage;
use App\Domain\Platform\Search;
use App\Models\Organization;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The platform's list of organizations: search, filter, sort, paginate.
 *
 * Filters are whitelisted here, not trusted: an unknown status or sort column
 * is ignored, a search term is data (wildcards escaped). The page costs a fixed
 * number of queries whatever its size: count, rows, live subscription, plan,
 * staff counts (the last one a single grouped statement for the whole page).
 */
final class PlatformOrganizationQuery
{
    public const SORTS = ['name', 'created_at'];

    public const PER_PAGE = 25;

    /** Plan filter value meaning "organizations without a live subscription". */
    public const NO_PLAN = 'none';

    public function __construct(private readonly OrganizationUsage $usage) {}

    /**
     * @param  array{q?: mixed, status?: mixed, plan?: mixed, sort?: mixed, direction?: mixed}  $filters
     * @return LengthAwarePaginator<int, OrganizationListItem>
     */
    public function paginate(array $filters = [], int $perPage = self::PER_PAGE, ?int $page = null): LengthAwarePaginator
    {
        $term = Search::clean($filters['q'] ?? null);
        $status = $this->choice($filters['status'] ?? null, OrganizationStatus::values());
        $plan = $this->planKey($filters['plan'] ?? null);
        $sort = $this->choice($filters['sort'] ?? null, self::SORTS) ?? 'created_at';
        $direction = $this->choice($filters['direction'] ?? null, ['asc', 'desc']) ?? ($sort === 'name' ? 'asc' : 'desc');

        $organizations = Organization::query()
            // Only what the list shows: the rest of the row is never loaded.
            ->select(['id', 'slug', 'name', 'status', 'email', 'created_at'])
            ->with(['liveSubscription' => fn ($query) => $query
                ->select(['id', 'organization_id', 'plan_id', 'status'])
                ->with('plan:id,key,name')])
            ->when($term !== '', function ($query) use ($term) {
                $like = Search::contains($term);
                $query->where(fn ($match) => $match
                    ->where('name', 'ilike', $like)
                    ->orWhere('slug', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like));
            })
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($plan === self::NO_PLAN, fn ($query) => $query->whereDoesntHave('liveSubscription'))
            ->when($plan !== null && $plan !== self::NO_PLAN, fn ($query) => $query
                ->whereHas('liveSubscription', fn ($subscription) => $subscription
                    ->whereHas('plan', fn ($planQuery) => $planQuery->where('key', $plan))))
            ->orderBy($sort, $direction)
            ->orderBy('id', $direction)
            ->paginate(max(1, min($perPage, 100)), ['*'], 'page', $page);

        $staff = $this->usage->staffCounts($organizations->pluck('id')->all());

        return $organizations->through(function (Organization $organization) use ($staff) {
            $subscription = $organization->liveSubscription;

            return new OrganizationListItem(
                slug: $organization->slug,
                name: $organization->name,
                email: $organization->email,
                status: $organization->status,
                planKey: $subscription?->plan->key,
                planName: $subscription?->plan->name,
                subscriptionStatus: $subscription?->status,
                staffCount: $staff[$organization->id] ?? 0,
                createdAt: $organization->created_at,
            );
        });
    }

    /** @param list<string> $allowed */
    private function choice(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private function planKey(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z0-9_-]{1,40}$/', $value) ? $value : null;
    }
}
