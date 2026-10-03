<?php

namespace App\Domain\Platform\Queries;

use App\Domain\Platform\Search;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Pagination\Paginator;

/**
 * The platform audit log. Every query starts from AuditLog::platformVisible(),
 * so platform actions and system events are all that can ever be listed:
 * activity inside an organization (clinical or administrative) is out of
 * reach of every filter. The log only grows, so it is paged with "simple"
 * pagination (never counted) and newest first.
 */
final class PlatformAuditQuery
{
    public const PER_PAGE = 50;

    /** Most accounts an actor search may match (a prefix such as "a" must not fan out). */
    private const ACTOR_MATCHES = 50;

    /**
     * Filters: action (dot-namespaced prefix, e.g. "subscription."), actor (an
     * email address: exact when it contains "@", otherwise the start of one),
     * context (platform|system), from (inclusive instant), before (exclusive instant).
     *
     * @param  array{action?: mixed, actor?: mixed, context?: mixed, from?: ?CarbonInterface, before?: ?CarbonInterface}  $filters
     * @return Paginator<int, PlatformAuditEntry>
     */
    public function paginate(array $filters = [], int $perPage = self::PER_PAGE, ?int $page = null): Paginator
    {
        $action = mb_strtolower(Search::clean($filters['action'] ?? null));
        $actor = mb_strtolower(Search::clean($filters['actor'] ?? null, 254));
        $context = is_string($filters['context'] ?? null) && in_array($filters['context'], ['platform', 'system'], true) ? $filters['context'] : null;
        $from = ($filters['from'] ?? null) instanceof CarbonInterface ? CarbonImmutable::instance($filters['from'])->utc() : null;
        $before = ($filters['before'] ?? null) instanceof CarbonInterface ? CarbonImmutable::instance($filters['before'])->utc() : null;

        $entries = AuditLog::query()
            ->platformVisible()
            ->when($context !== null, fn ($query) => $query->where('context', $context))
            ->when($action !== '', fn ($query) => $query->where('action', 'like', Search::prefix($action)))
            ->when($actor !== '', function ($query) use ($actor) {
                $ids = User::query()
                    ->when(
                        str_contains($actor, '@'),
                        fn ($users) => $users->whereRaw('lower(email) = ?', [$actor]),
                        fn ($users) => $users->whereRaw('lower(email) like ?', [Search::prefix($actor)]),
                    )
                    ->limit(self::ACTOR_MATCHES)
                    ->pluck('id');

                $query->whereIn('actor_user_id', $ids);
            })
            ->when($from !== null, fn ($query) => $query->where('occurred_at', '>=', $from))
            ->when($before !== null, fn ($query) => $query->where('occurred_at', '<', $before))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->simplePaginate(max(1, min($perPage, 100)), ['*'], 'page', $page);

        // Name the organizations on this page in one lookup.
        $organizationIds = $entries->getCollection()->pluck('organization_id')->filter()->unique()->values()->all();
        $organizations = $organizationIds === []
            ? collect()
            : Organization::query()->whereIn('id', $organizationIds)->get(['id', 'name', 'slug'])->keyBy('id');

        return $entries->through(fn (AuditLog $entry) => new PlatformAuditEntry(
            occurredAt: $entry->occurred_at,
            context: $entry->context,
            action: $entry->action,
            actorLabel: $entry->actor_label,
            summary: $entry->summary,
            organizationName: $organizations->get($entry->organization_id)?->name,
            organizationSlug: $organizations->get($entry->organization_id)?->slug,
            before: $entry->before,
            after: $entry->after,
            metadata: $entry->metadata,
            ip: $entry->ip,
            userAgent: $entry->user_agent,
        ));
    }
}
