<?php

namespace App\Domain\Scheduling;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Tenancy\TenantContext;
use App\Models\BlockedTime;
use Illuminate\Support\Facades\DB;

/** Removes blocked time (the time becomes bookable again); the audit entry keeps what it was. */
final class DeleteBlockedTime
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(BlockedTime $blockedTime): void
    {
        TenantGuard::assertOwned($this->tenant->organizationOrFail()->id, $blockedTime);

        DB::transaction(function () use ($blockedTime) {
            $this->audit->record(
                'availability.blocked_time_deleted',
                subject: $blockedTime,
                before: CreateBlockedTime::snapshot($blockedTime),
                summary: 'Blocked time removed',
            );

            $blockedTime->delete();
        });
    }
}
