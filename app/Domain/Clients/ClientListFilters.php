<?php

namespace App\Domain\Clients;

use Illuminate\Support\Str;

/**
 * The client list's query-string, sanitised: anything unknown or malformed
 * falls back to the default instead of failing the page (these are GET
 * filters typed into a URL bar, not form input to be rejected).
 */
final readonly class ClientListFilters
{
    /** Default: everyone still in care (active and inactive), not archived. */
    public const STATUS_OPEN = 'open';

    public const STATUSES = ['open', 'active', 'inactive', 'archived', 'all'];

    public const RECORDS = ['all', 'live', 'demo'];

    public const SORTS = ['last_name', 'client_number', 'created'];

    public const UNASSIGNED = 'none';

    public function __construct(
        public string $q = '',
        public string $status = self::STATUS_OPEN,
        public string $records = 'all',
        /** A membership id, or "none" for clients without a primary clinician. */
        public ?string $clinician = null,
        public string $sort = 'last_name',
        public string $direction = 'asc',
    ) {}

    /** @param array<string, mixed> $query */
    public static function from(array $query): self
    {
        $text = static fn (string $key): string => is_string($query[$key] ?? null) ? trim($query[$key]) : '';
        $oneOf = static fn (string $value, array $allowed, string $default): string => in_array($value, $allowed, true) ? $value : $default;

        $clinician = $text('clinician');

        return new self(
            q: mb_substr($text('q'), 0, ClientSearchTerm::MAX_LENGTH),
            status: $oneOf($text('status'), self::STATUSES, self::STATUS_OPEN),
            records: $oneOf($text('records'), self::RECORDS, 'all'),
            clinician: ($clinician === self::UNASSIGNED || Str::isUuid($clinician)) ? $clinician : null,
            sort: $oneOf($text('sort'), self::SORTS, 'last_name'),
            direction: strtolower($text('direction')) === 'desc' ? 'desc' : 'asc',
        );
    }

    /** True when the list is narrowed in any way beyond the defaults (decides which empty state to show). */
    public function isNarrowed(): bool
    {
        return $this->q !== ''
            || $this->status !== self::STATUS_OPEN
            || $this->records !== 'all'
            || $this->clinician !== null;
    }

    /** @return list<string> the status values the filter allows */
    public function statuses(): array
    {
        return match ($this->status) {
            'active' => ['active'],
            'inactive' => ['inactive'],
            'archived' => ['archived'],
            'all' => ClientStatus::values(),
            default => ['active', 'inactive'],
        };
    }
}
