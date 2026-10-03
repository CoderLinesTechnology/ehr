<?php

namespace App\Domain\Clients\Events;

use App\Models\Client;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A client record was created (live clients only: the demo-data module writes
 * demo clients itself). Dispatched after commit; the timeline listener lives
 * next to it, and notifications or analytics can subscribe later without
 * touching CreateClient.
 */
final class ClientCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Client $client, public readonly ?string $actorUserId) {}
}
