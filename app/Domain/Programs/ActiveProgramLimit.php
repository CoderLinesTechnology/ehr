<?php

namespace App\Domain\Programs;

use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Saas\LimitReached;
use App\Models\Organization;
use App\Models\Program;
use Illuminate\Support\Facades\DB;

/**
 * The plan's max_programs limit: it counts OPEN programs (upcoming, active, on hold). Completed and archived
 * programs free their place. Same discipline as ActiveClientLimit: the organization row is locked
 * (FOR NO KEY UPDATE) before counting, so two administrators cannot both take the last place. Call it inside the
 * transaction that writes the program.
 */
final class ActiveProgramLimit
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** @throws LimitReached */
    public function assertRoomFor(Organization $organization, int $adding = 1): void
    {
        if ($this->entitlements->limit($organization, FeatureRegistry::MAX_PROGRAMS) === null) {
            return;
        }

        DB::table('organizations')->where('id', $organization->id)->lock('for no key update')->value('id');

        $this->entitlements->assertWithinLimit($organization, FeatureRegistry::MAX_PROGRAMS, $this->current(), $adding);
    }

    public function current(): int
    {
        return Program::query()->whereIn('status', ProgramStatus::OPEN)->count();
    }
}
