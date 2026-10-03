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

    public const STATUSES = ['open', 'pending', 'active', 'inactive', 'archived', 'all'];

    public const RECORDS = ['all', 'live', 'demo'];

    public const SORTS = ['last_name', 'client_number', 'created'];

    public const UNASSIGNED = 'none';

    /** Location filter value: clients whose primary location is "Virtual (telehealth)". */
    public const VIRTUAL = 'virtual';

    public function __construct(
        public string $q = '',
        public string $status = self::STATUS_OPEN,
        public string $records = 'all',
        /** A membership id, or "none" for clients without a primary clinician. */
        public ?string $clinician = null,
        public string $sort = 'last_name',
        public string $direction = 'asc',
        /** A location id (the client's primary location), or "virtual". */
        public ?string $location = null,
        /** Y-m-d: only clients with a completed visit on or after / before this day (the organization's calendar). */
        public ?string $visitedFrom = null,
        public ?string $visitedTo = null,
        /** Only clients whose primary clinician is the viewer. */
        public bool $mine = false,
        /** adult | minor | couple */
        public ?string $type = null,
        /** self_pay | insurance */
        public ?string $billing = null,
    ) {}

    /** @param array<string, mixed> $query */
    public static function from(array $query): self
    {
        $text = static fn (string $key): string => is_string($query[$key] ?? null) ? trim($query[$key]) : '';
        $oneOf = static fn (string $value, array $allowed, string $default): string => in_array($value, $allowed, true) ? $value : $default;

        $clinician = $text('clinician');
        $location = $text('location');
        $day = static function (string $value): ?string {
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

            return ($date !== false && $date->format('Y-m-d') === $value && $date->format('Y') >= '1900') ? $value : null;
        };
        $from = $day($text('from'));
        $to = $day($text('to'));
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new self(
            q: mb_substr($text('q'), 0, ClientSearchTerm::MAX_LENGTH),
            status: $oneOf($text('status'), self::STATUSES, self::STATUS_OPEN),
            records: $oneOf($text('records'), self::RECORDS, 'all'),
            clinician: ($clinician === self::UNASSIGNED || Str::isUuid($clinician)) ? $clinician : null,
            sort: $oneOf($text('sort'), self::SORTS, 'last_name'),
            direction: strtolower($text('direction')) === 'desc' ? 'desc' : 'asc',
            location: (Str::isUuid($location) || $location === self::VIRTUAL) ? strtolower($location) : null,
            visitedFrom: $from,
            visitedTo: $to,
            mine: in_array($text('mine'), ['1', 'on', 'true'], true),
            type: in_array($text('type'), ClientType::values(), true) ? $text('type') : null,
            billing: in_array($text('billing'), BillingType::values(), true) ? $text('billing') : null,
        );
    }

    /** True when the list is narrowed in any way beyond the defaults (decides which empty state to show). */
    public function isNarrowed(): bool
    {
        return $this->q !== ''
            || $this->status !== self::STATUS_OPEN
            || $this->records !== 'all'
            || $this->clinician !== null
            || $this->location !== null
            || $this->visitedFrom !== null
            || $this->visitedTo !== null
            || $this->mine
            || $this->type !== null
            || $this->billing !== null;
    }

    /** @return list<string> the status values the filter allows */
    public function statuses(): array
    {
        return match ($this->status) {
            'active' => ['active'],
            'pending' => ['pending'],
            'inactive' => ['inactive'],
            'archived' => ['archived'],
            'all' => ClientStatus::values(),
            default => ['pending', 'active', 'inactive'],
        };
    }
}
