<?php

namespace App\Domain\Platform\Queries;

use Carbon\CarbonInterface;

/** One row of the platform's account list: who they are and whether they can sign in. Nothing about their work. */
final readonly class PlatformUserListItem
{
    public function __construct(
        /** The account's route key, for building links. Never shown as text. */
        public string $id,
        public string $name,
        public string $email,
        public bool $disabled,
        public bool $emailVerified,
        /** Organizations the account is an active member of. */
        public int $organizationsCount,
        /** @var list<string> names of the platform roles held, if any */
        public array $platformRoles,
        public ?CarbonInterface $lastLoginAt,
        public CarbonInterface $createdAt,
    ) {}
}
