<?php

namespace App\Domain\Clients;

use App\Models\TimelineEntry;
use Illuminate\Support\Collection;

/** One page of a client's timeline, newest first, and the cursor of the next (older) page. */
final readonly class TimelinePage
{
    /** @param Collection<int, TimelineEntry> $entries */
    public function __construct(
        public Collection $entries,
        public ?string $next,
    ) {}
}
