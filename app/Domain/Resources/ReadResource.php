<?php

namespace App\Domain\Resources;

use App\Domain\Identity\PermissionResolver;
use App\Models\OrganizationMembership;
use App\Models\Resource;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read model of the Resources screen. A fixed number of queries whatever the data: category counts (one
 * grouped query), the featured row (one), the latest list (one, plus one count when paginated).
 *
 * What a member sees:
 *   resources.view            published resources for staff or everyone
 *   resources.manage          every resource of the organization: any audience, and drafts/archived via ?status=
 *
 * List queries select only the columns the cards show (never the body or the file path's folder).
 */
final class ReadResource
{
    public const FEATURED_LIMIT = SaveResource::FEATURED_LIMIT;

    public const LATEST_LIMIT = 6;

    public const PAGE_SIZE = 12;

    private const CARD_COLUMNS = ['id', 'type', 'title', 'summary', 'reading_minutes', 'file_path', 'audience', 'status', 'is_featured', 'published_at'];

    public function __construct(private readonly PermissionResolver $permissions) {}

    public function canManage(OrganizationMembership $membership): bool
    {
        return $membership->isActive() && $this->permissions->membershipHas($membership, 'resources.manage');
    }

    /**
     * @return array{
     *   featured: Collection<int, Resource>, latest: Collection<int, Resource>|Paginator, hasMore: bool,
     *   counts: array<string, int>, total: int
     * }
     */
    public function page(OrganizationMembership $membership, ResourceFilters $filters): array
    {
        $manager = $this->canManage($membership);
        $counts = $this->counts($manager, $filters);

        if ($filters->isListing()) {
            $paginator = $this->scoped($manager, $filters->type, $filters)
                ->select(self::CARD_COLUMNS)
                ->orderByDesc('published_at')->orderByDesc('id')
                ->paginate(self::PAGE_SIZE)->withQueryString();

            return ['featured' => collect(), 'latest' => $paginator, 'hasMore' => false, 'counts' => $counts, 'total' => array_sum($counts)];
        }

        $featured = $this->scoped($manager, $filters->type, $filters)
            ->where('is_featured', true)
            ->select(self::CARD_COLUMNS)
            ->orderByDesc('published_at')->orderByDesc('id')
            ->limit(self::FEATURED_LIMIT)->get();

        $latest = $this->scoped($manager, $filters->type, $filters)
            ->where('is_featured', false)
            ->select(self::CARD_COLUMNS)
            ->orderByDesc('published_at')->orderByDesc('id')
            ->limit(self::LATEST_LIMIT + 1)->get();

        return [
            'featured' => $featured,
            'latest' => $latest->take(self::LATEST_LIMIT)->values(),
            'hasMore' => $latest->count() > self::LATEST_LIMIT,
            'counts' => $counts,
            'total' => array_sum($counts),
        ];
    }

    /** One resource for its page. Null when this member may not see it (the policy gives the same answer). */
    public function find(OrganizationMembership $membership, string $id): ?Resource
    {
        if (! \Illuminate\Support\Str::isUuid($id)) {
            return null;
        }

        return $this->visibility(Resource::query()->whereKey($id), $this->canManage($membership))->first();
    }

    /**
     * Resources per type for the tabs (the tab's screen-reader count); a search narrows them, a type does not.
     *
     * @return array<string, int>
     */
    private function counts(bool $manager, ResourceFilters $filters): array
    {
        $rows = $this->scoped($manager, null, $filters)
            ->toBase()->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');

        $counts = [];
        foreach (ResourceType::cases() as $type) {
            $counts[$type->value] = (int) ($rows[$type->value] ?? 0);
        }

        return $counts;
    }

    /** @return Builder<Resource> */
    private function scoped(bool $manager, ?ResourceType $type, ResourceFilters $filters): Builder
    {
        $query = $this->visibility(Resource::query(), $manager);

        if ($manager && $filters->status !== null) {
            $query->where('status', $filters->status->value);
        } else {
            $query->where('status', ResourceStatus::Published->value);
        }
        if ($type !== null) {
            $query->where('type', $type->value);
        }
        foreach ($filters->words as $word) {
            $query->whereRaw("search_text like ? escape '\\'", ['%'.addcslashes($word, '\\%_').'%']);
        }

        return $query;
    }

    /** @param Builder<Resource> $query @return Builder<Resource> */
    private function visibility(Builder $query, bool $manager): Builder
    {
        if ($manager) {
            return $query;
        }

        return $query->where('status', ResourceStatus::Published->value)
            ->whereIn('audience', [ResourceAudience::Staff->value, ResourceAudience::Everyone->value]);
    }
}
