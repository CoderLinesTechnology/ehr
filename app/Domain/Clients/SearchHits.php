<?php

namespace App\Domain\Clients;

use Illuminate\Support\Collection;

/**
 * A bounded list of search results. $more says whether anything beyond the
 * limit exists (one extra row is fetched to know) so a screen can point to
 * the full list instead of silently truncating.
 *
 * @template TItem
 */
final readonly class SearchHits
{
    /** @param Collection<int, TItem> $items */
    public function __construct(
        public Collection $items,
        public bool $more = false,
    ) {}

    public static function none(): self
    {
        return new self(collect(), false);
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }
}
