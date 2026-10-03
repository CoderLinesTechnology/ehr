<?php

namespace App\Domain\Clients;

use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Saas\LimitReached;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * The plan's max_active_clients limit. It counts ACTIVE clients of the LIVE
 * environment only: demo clients never count, and inactive or archived
 * clients free their place.
 *
 * "Count, then insert" is a race: two receptionists adding the 150th client
 * at once would both pass. assertRoomFor() therefore takes a lock on the
 * organization row (FOR NO KEY UPDATE: it conflicts with other holders of the
 * same lock but not with the foreign-key checks of ordinary inserts) before
 * it counts, so creating and restoring clients of one organization queue up.
 * Call it inside the transaction that writes the client.
 */
final class ActiveClientLimit
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * @throws LimitReached
     */
    public function assertRoomFor(Organization $organization, int $adding = 1): void
    {
        // Unlimited: nothing to protect, nothing to serialise.
        if ($this->entitlements->limit($organization, FeatureRegistry::MAX_ACTIVE_CLIENTS) === null) {
            return;
        }

        DB::table('organizations')->where('id', $organization->id)->lock('for no key update')->value('id');

        $this->entitlements->assertWithinLimit($organization, FeatureRegistry::MAX_ACTIVE_CLIENTS, $this->current(), $adding);
    }

    /** Active live clients of the current organization. */
    public function current(): int
    {
        return Client::query()->live()->where('status', ClientStatus::Active->value)->count();
    }
}
