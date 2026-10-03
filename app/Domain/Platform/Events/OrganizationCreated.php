<?php

namespace App\Domain\Platform\Events;

use App\Models\Organization;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class OrganizationCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Organization $organization, public readonly ?string $actorUserId) {}
}
