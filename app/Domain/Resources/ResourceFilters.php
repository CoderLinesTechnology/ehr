<?php

namespace App\Domain\Resources;

/**
 * What the Resources page was asked for, understood: a type tab, a search, "view all", and (managers only) a status.
 * Everything unknown is ignored rather than refused: a stale link still shows the page.
 */
final readonly class ResourceFilters
{
    public const MAX_QUERY = 100;

    public const MAX_WORDS = 5;

    /** @param list<string> $words every one must occur in the title or summary */
    public function __construct(
        public ?ResourceType $type = null,
        public string $q = '',
        public array $words = [],
        public bool $all = false,
        public ?ResourceStatus $status = null,
    ) {}

    /** @param array<string, mixed> $query */
    public static function from(array $query, bool $canManage): self
    {
        $type = is_string($query['type'] ?? null) ? ResourceType::tryFrom($query['type']) : null;

        $q = is_string($query['q'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $query['q'])) : '';
        $q = trim(mb_substr($q, 0, self::MAX_QUERY));
        $words = $q === '' ? [] : array_slice(array_map(fn (string $w) => mb_strtolower($w), explode(' ', $q)), 0, self::MAX_WORDS);

        $status = $canManage && is_string($query['status'] ?? null) ? ResourceStatus::tryFrom($query['status']) : null;
        $all = ($query['view'] ?? null) === 'all';

        return new self($type, $q, $words, $all, $status);
    }

    /** True when the page shows one flat, paginated list instead of "featured + latest". */
    public function isListing(): bool
    {
        return $this->all || $this->q !== '' || $this->status !== null;
    }

    /** @return array<string, string> the query string that keeps this filter, plus overrides */
    public function query(array $override = []): array
    {
        $base = array_filter([
            'type' => $this->type?->value,
            'q' => $this->q !== '' ? $this->q : null,
            'view' => $this->all ? 'all' : null,
            'status' => $this->status?->value,
        ], fn ($v) => $v !== null);

        return array_filter($override + $base, fn ($v) => $v !== null && $v !== '');
    }
}
