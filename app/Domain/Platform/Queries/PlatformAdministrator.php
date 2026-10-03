<?php

namespace App\Domain\Platform\Queries;

use Carbon\CarbonInterface;

/** A person who holds at least one platform role, with each role and who granted it. */
final readonly class PlatformAdministrator
{
    public function __construct(
        /** Route key for building links; never shown as text. */
        public string $id,
        public string $name,
        public string $email,
        public bool $disabled,
        /** Whether two-factor authentication is confirmed (required to open the console). */
        public bool $twoFactorConfirmed,
        public ?CarbonInterface $lastLoginAt,
        /** @var list<array{key: string, name: string, granted_at: ?CarbonInterface, granted_by: ?string}> */
        public array $roles,
    ) {}
}
