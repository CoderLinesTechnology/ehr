<?php

namespace App\Domain\Clients;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantMismatch;
use App\Models\Client;
use App\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds clients by what staff type: name, e-mail, phone number (local or
 * international; ANY of the client's e-mails and phones, not only the
 * primary ones) or client number. Always confined to the organization
 * (tenant scope) and to the clients the searcher may see (ClientVisibility);
 * always bounded.
 *
 * The other e-mails and phones reach search_text through clients.contact_search,
 * which a trigger on client_contact_points keeps current: the search stays one
 * trigram-indexed column, with no join and no OR across tables.
 *
 * The text match is `search_text ILIKE '%word%'`, which the trigram GIN index
 * on clients.search_text serves. Keep that exact shape when changing this
 * (words too short for a trigram are written `(… ILIKE …) IS TRUE` so they
 * stay plain filters); ClientSearchTerm explains how a typed term becomes
 * words, a number or a name prefix.
 */
final class ClientSearch
{
    public const DEFAULT_LIMIT = 10;

    public const MAX_LIMIT = 50;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Clients matching $term that $membership may see, best matches first:
     * the exact client number, then everyone not archived, then by name.
     * Nothing typed (or nothing that can match) finds nothing: the full list
     * is ClientDirectory's job.
     *
     * @return SearchHits<Client>
     */
    public function find(OrganizationMembership $membership, ?string $term, int $limit = self::DEFAULT_LIMIT): SearchHits
    {
        if ($membership->organization_id !== $this->tenant->id()) {
            throw new TenantMismatch('The searching member does not belong to the current organization.');
        }

        $parsed = ClientSearchTerm::parse($term);

        if ($parsed->isEmpty() || $parsed->matchesNothing() || ! ClientVisibility::hasAnyAccess($membership)) {
            return SearchHits::none();
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));

        $query = Client::query()->select(ClientDirectory::COLUMNS);
        ClientVisibility::apply($query, $membership);
        self::scope($query, $parsed);

        if ($parsed->number !== null) {
            $query->orderByRaw('clients.client_number = ? DESC', [$parsed->number]);
        }

        // One more than asked for, to know whether there are others.
        $rows = $query
            ->orderByRaw("clients.status = 'archived'")
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->limit($limit + 1)
            ->get();

        return new SearchHits($rows->take($limit)->values(), $rows->count() > $limit);
    }

    /**
     * Add a typed term to any Client query (the list's search box).
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public static function apply(Builder $query, ?string $term): Builder
    {
        $parsed = ClientSearchTerm::parse($term);

        return $parsed->isEmpty() ? $query : self::scope($query, $parsed);
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public static function scope(Builder $query, ClientSearchTerm $term): Builder
    {
        if ($term->matchesNothing()) {
            return $query->whereRaw('1 = 0');
        }

        $clients = $query->getModel()->getTable();

        return $query->where(function (Builder $match) use ($term, $clients) {
            if ($term->number !== null) {
                $match->orWhere("{$clients}.client_number", $term->number);
            }

            if ($term->words !== []) {
                $match->orWhere(function (Builder $words) use ($term, $clients) {
                    foreach ($term->words as $word) {
                        // A word with fewer than three letters or digits has no trigram: PostgreSQL would still
                        // pick the GIN index for it and read a large share of it. `IS TRUE` makes it a plain filter.
                        $indexable = mb_strlen((string) preg_replace('/[^\p{L}\p{N}]/u', '', $word)) >= 3;

                        $words->whereRaw(
                            $indexable ? "{$clients}.search_text ILIKE ?" : "({$clients}.search_text ILIKE ?) IS TRUE",
                            ['%'.self::escapeLike($word).'%'],
                        );
                    }
                });
            }

            if ($term->namePrefix !== null) {
                $prefix = self::escapeLike($term->namePrefix).'%';

                $match->orWhere(function (Builder $names) use ($clients, $prefix) {
                    $names->whereRaw("{$clients}.first_name ILIKE ?", [$prefix])
                        ->orWhereRaw("{$clients}.last_name ILIKE ?", [$prefix])
                        ->orWhereRaw("{$clients}.preferred_name ILIKE ?", [$prefix]);
                });
            }
        });
    }

    /** Make LIKE wildcards in typed text literal ("50%" must not match everything). */
    public static function escapeLike(string $text): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text);
    }
}
