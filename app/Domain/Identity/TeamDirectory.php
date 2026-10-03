<?php

namespace App\Domain\Identity;

use App\Models\OrganizationMembership;
use App\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The team list: every membership of the current organization (any status), searchable,
 * filterable by status and paginated. Users and roles are loaded for the whole page at once
 * (two extra queries however many members there are); the roles are batch-loaded by hand
 * because the membership → roles relation cannot be eager loaded.
 */
final class TeamDirectory
{
    /** @return LengthAwarePaginator<int, OrganizationMembership> */
    public function paginate(?string $status = null, ?string $search = null, int $perPage = 25): LengthAwarePaginator
    {
        $query = OrganizationMembership::query()
            ->leftJoin('users', 'users.id', '=', 'organization_memberships.user_id')
            ->select('organization_memberships.*')
            ->with('user:id,name,email');

        if ($status !== null && in_array($status, MembershipStatus::values(), true)) {
            $query->where('organization_memberships.status', $status);
        }

        $term = $search === null ? '' : trim($search);
        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
            $query->where(fn ($q) => $q
                ->whereRaw('lower(users.name) like ?', [$like])
                ->orWhereRaw('lower(users.email) like ?', [$like])
                ->orWhereRaw('lower(organization_memberships.invited_email) like ?', [$like]));
        }

        $page = $query
            ->orderByRaw("case organization_memberships.status when 'active' then 0 when 'invited' then 1 when 'suspended' then 2 else 3 end")
            ->orderByRaw('lower(coalesce(users.name, organization_memberships.invited_email))')
            ->orderBy('organization_memberships.id')
            ->paginate($perPage)
            ->withQueryString();

        $this->attachRoles($page->getCollection());

        return $page;
    }

    /** @return array<string, int> status value => number of memberships (all statuses present, zero included) */
    public function statusCounts(): array
    {
        $counts = array_fill_keys(MembershipStatus::values(), 0);

        foreach (OrganizationMembership::query()->selectRaw('status, count(*) as total')->groupBy('status')->get() as $row) {
            $counts[$row->status->value] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * Sets the `roles` relation of each member from one query.
     *
     * @param  Collection<int, OrganizationMembership>  $members
     */
    public function attachRoles(Collection $members): void
    {
        $ids = $members->pluck('id')->all();
        $byMember = [];

        if ($ids !== []) {
            $rows = Role::query()
                ->join('membership_roles', 'membership_roles.role_id', '=', 'roles.id')
                ->whereIn('membership_roles.membership_id', $ids)
                ->orderBy('roles.name')
                ->get(['roles.id', 'roles.key', 'roles.name', 'roles.is_locked', 'membership_roles.membership_id as holder_id']);

            foreach ($rows as $role) {
                $byMember[$role->getAttribute('holder_id')][] = $role;
            }
        }

        foreach ($members as $member) {
            $member->setRelation('roles', new Collection($byMember[$member->id] ?? []));
        }
    }

    /**
     * Services a provider is linked to, for the member page.
     *
     * @return list<string> service names
     */
    public function serviceNames(OrganizationMembership $member): array
    {
        return DB::table('service_providers')
            ->join('services', 'services.id', '=', 'service_providers.service_id')
            ->where('service_providers.organization_id', $member->organization_id)
            ->where('service_providers.membership_id', $member->id)
            ->orderBy('services.name')
            ->pluck('services.name')
            ->all();
    }
}
