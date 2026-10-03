<?php

namespace App\Domain\Programs;

/** What the programs overview was asked to show, parsed defensively from the query string. */
final readonly class ProgramFilters
{
    public function __construct(public ?ProgramStatus $status = null, public string $q = '') {}

    /** @param array<string, mixed> $query */
    public static function from(array $query): self
    {
        $status = isset($query['status']) && is_string($query['status']) ? ProgramStatus::tryFrom($query['status']) : null;
        $q = isset($query['q']) && is_string($query['q']) ? trim(mb_substr($query['q'], 0, 100)) : '';

        return new self($status, $q);
    }

    /** @param array<string, string|null> $override @return array<string, string> */
    public function query(array $override = []): array
    {
        $all = ['status' => $this->status?->value, 'q' => $this->q !== '' ? $this->q : null];

        return array_filter(array_merge($all, $override), fn ($v) => $v !== null && $v !== '');
    }
}
