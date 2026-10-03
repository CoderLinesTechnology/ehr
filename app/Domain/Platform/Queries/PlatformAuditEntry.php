<?php

namespace App\Domain\Platform\Queries;

use Carbon\CarbonInterface;

/** One line of the platform audit log, with the organization it concerns (if any) already named. */
final readonly class PlatformAuditEntry
{
    public function __construct(
        public CarbonInterface $occurredAt,
        /** platform | system */
        public string $context,
        public string $action,
        public ?string $actorLabel,
        public ?string $summary,
        public ?string $organizationName,
        public ?string $organizationSlug,
        /** @var array<string, mixed>|null */
        public ?array $before,
        /** @var array<string, mixed>|null */
        public ?array $after,
        /** @var array<string, mixed>|null */
        public ?array $metadata,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
