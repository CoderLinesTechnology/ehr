<?php

namespace App\Domain\Telehealth;

/** A page of the sessions list with the tab counts. @property list<SessionRow> $rows */
final readonly class SessionListPage
{
    /**
     * @param  list<SessionRow>  $rows
     * @param  array{upcoming: int, past: int, all: int}  $counts
     */
    public function __construct(
        public string $tab,
        public array $rows,
        public array $counts,
        public int $page,
        public int $perPage,
    ) {}

    public function total(): int
    {
        return $this->counts[$this->tab];
    }

    public function from(): int
    {
        return $this->rows === [] ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function to(): int
    {
        return $this->rows === [] ? 0 : $this->from() + count($this->rows) - 1;
    }

    public function hasMore(): bool
    {
        return $this->to() < $this->total();
    }
}
