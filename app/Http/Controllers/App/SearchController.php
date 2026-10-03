<?php

namespace App\Http\Controllers\App;

use App\Domain\Clients\ClientDirectory;
use App\Domain\Clients\ClientSearch;
use App\Domain\Clients\ClientVisibility;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\OrganizationMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Global search (the box in the top bar): clients and staff, each section
 * only for people allowed to see it and searched only through fields they
 * could read on the list anyway. Clients are limited to those the member may
 * see (ClientVisibility), ten at most; staff need `team.view`, five at most.
 */
final class SearchController extends Controller
{
    public const CLIENT_LIMIT = 10;

    public const STAFF_LIMIT = 5;

    public function __invoke(Request $request, EntitlementService $entitlements): View
    {
        $term = is_string($request->query('q')) ? trim(mb_substr($request->query('q'), 0, 100)) : '';
        $organization = tenant()->organizationOrFail();
        $membership = tenant()->membership();

        $canSearchClients = Gate::allows('viewAny', Client::class) && $entitlements->allows($organization, FeatureRegistry::CLIENTS);
        $canSearchStaff = Gate::allows('team.view');

        $clients = collect();
        $staff = collect();

        if ($term !== '' && $canSearchClients) {
            $query = Client::query()->select(ClientDirectory::COLUMNS);
            ClientVisibility::apply($query, $membership);
            ClientSearch::apply($query, $term);

            // One more than shown, to know whether to point at the full list.
            $clients = $query
                ->orderByRaw("status = 'archived'")
                ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
                ->limit(self::CLIENT_LIMIT + 1)
                ->get();
        }

        if ($term !== '' && $canSearchStaff) {
            $staff = $this->staff($term);
        }

        return view('app.search.index', [
            'term' => $term,
            'clients' => $clients->take(self::CLIENT_LIMIT),
            'moreClients' => $clients->count() > self::CLIENT_LIMIT,
            'staff' => $staff->take(self::STAFF_LIMIT),
            'moreStaff' => $staff->count() > self::STAFF_LIMIT,
            'canSearchClients' => $canSearchClients,
            'canSearchStaff' => $canSearchStaff,
            'country' => $organization->country_code,
        ]);
    }

    /**
     * Active staff of this organization by name or e-mail (every word must
     * match one of them). Work is bounded by the organization's own staff:
     * the join starts from its memberships, never from the global user table.
     *
     * @return \Illuminate\Support\Collection<int, OrganizationMembership>
     */
    private function staff(string $term): \Illuminate\Support\Collection
    {
        $query = OrganizationMembership::query()
            ->join('users', 'users.id', '=', 'organization_memberships.user_id')
            ->where('organization_memberships.status', 'active')
            ->where('users.status', 'active');

        foreach (array_slice(preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5) as $word) {
            $like = '%'.ClientSearch::escapeLike($word).'%';
            $query->where(fn ($match) => $match->where('users.name', 'ilike', $like)->orWhere('users.email', 'ilike', $like));
        }

        return $query
            ->select('organization_memberships.*')
            ->orderBy('users.name')->orderBy('organization_memberships.id')
            ->limit(self::STAFF_LIMIT + 1)
            ->with('user:id,name,email')
            ->get();
    }
}
