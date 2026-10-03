<?php

namespace App\Domain\Telehealth;

use Carbon\CarbonImmutable;

/** One line of the sessions list: names and times only, no link, no clinical content. */
final readonly class SessionRow
{
    public function __construct(
        public string $id,
        public SessionStatus $status,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $timezone,
        public string $clientName,
        public string $clientNumber,
        public string $serviceName,
        public string $clinicianName,
        public bool $joinable,
    ) {}
}
