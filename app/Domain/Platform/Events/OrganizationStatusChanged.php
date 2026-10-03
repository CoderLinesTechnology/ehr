<?php

namespace App\Domain\Platform\Events;

use App\Domain\Platform\OrganizationStatus;
use App\Models\Organization;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class OrganizationStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Organization $organization,
        public readonly ?OrganizationStatus $from,
        public readonly OrganizationStatus $to,
        public readonly ?string $reason,
        public readonly ?string $actorUserId,
    ) {}
}
