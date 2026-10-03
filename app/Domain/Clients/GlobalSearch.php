<?php

namespace App\Domain\Clients;

use App\Domain\Identity\PermissionResolver;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantMismatch;
use App\Models\Client;
use App\Models\OrganizationMembership;

/**
 * The search box in the top bar: clients and staff in one go, each section
 * only for people who may use it and only through fields they could read
 * anyway.
 *
 *  - clients: the organization's plan includes the clients module AND the
 *    searcher may view clients; limited to the clients they may see
 *    (ClientVisibility), CLIENT_LIMIT at most;
 *  - staff: `team.view`; active staff of this organization by name or
 *    e-mail, STAFF_LIMIT at most.
 *
 * A section the searcher may not use is not searched at all (and says so),
 * so a result can never reveal what a screen would not.
 */
final class GlobalSearch
{
    public const CLIENT_LIMIT = 10;

    public const STAFF_LIMIT = 5;

    private const MAX_STAFF_WORDS = 5;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ClientSearch $clients,
        private readonly PermissionResolver $permissions,
        private readonly EntitlementService $entitlements,
    ) {}

    public function __invoke(OrganizationMembership $membership, ?string $term): GlobalSearchResults
    {
        $organization = $this->tenant->organizationOrFail();

        if ($membership->organization_id !== $organization->id) {
            throw new TenantMismatch('The searching member does not belong to the current organization.');
        }

        $text = trim(mb_substr(trim((string) $term), 0, ClientSearchTerm::MAX_LENGTH));

        // hasAnyAccess() already refuses a member who is no longer active; staff search needs the same.
        $canSearchClients = ClientVisibility::hasAnyAccess($membership)
            && $this->entitlements->allows($organization, FeatureRegistry::CLIENTS);
        $canSearchStaff = $membership->isActive() && $this->permissions->membershipHas($membership, 'team.view');

        return new GlobalSearchResults(
            term: $text,
            canSearchClients: $canSearchClients,
            canSearchStaff: $canSearchStaff,
            clients: ($text !== '' && $canSearchClients) ? $this->clients->find($membership, $text, self::CLIENT_LIMIT) : SearchHits::none(),
            staff: ($text !== '' && $canSearchStaff) ? $this->staff($text) : SearchHits::none(),
        );
    }

    /**
     * The clients section alone (the client pickers' type-ahead): the same guard and bound as the page,
     * without searching staff.
     *
     * @return SearchHits<Client>
     */
    public function clients(OrganizationMembership $membership, ?string $term): SearchHits
    {
        $organization = $this->tenant->organizationOrFail();

        if ($membership->organization_id !== $organization->id) {
            throw new TenantMismatch('The searching member does not belong to the current organization.');
        }

        $text = trim(mb_substr(trim((string) $term), 0, ClientSearchTerm::MAX_LENGTH));
        $allowed = ClientVisibility::hasAnyAccess($membership) && $this->entitlements->allows($organization, FeatureRegistry::CLIENTS);

        return ($text !== '' && $allowed) ? $this->clients->find($membership, $text, self::CLIENT_LIMIT) : SearchHits::none();
    }

    /**
     * Every word must match the name or the e-mail. The work is bounded by
     * the organization's own staff: the query starts from its memberships
     * (tenant scope), never from the global user table.
     *
     * @return SearchHits<OrganizationMembership>
     */
    private function staff(string $text): SearchHits
    {
        $query = OrganizationMembership::query()
            ->join('users', 'users.id', '=', 'organization_memberships.user_id')
            ->where('organization_memberships.status', 'active')
            ->where('users.status', 'active');

        foreach (array_slice(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, self::MAX_STAFF_WORDS) as $word) {
            $like = '%'.ClientSearch::escapeLike($word).'%';
            $query->where(fn ($match) => $match->where('users.name', 'ilike', $like)->orWhere('users.email', 'ilike', $like));
        }

        $rows = $query
            ->select('organization_memberships.*')
            ->orderBy('users.name')->orderBy('organization_memberships.id')
            ->limit(self::STAFF_LIMIT + 1)
            ->with('user:id,name,email')
            ->get();

        return new SearchHits($rows->take(self::STAFF_LIMIT)->values(), $rows->count() > self::STAFF_LIMIT);
    }
}
