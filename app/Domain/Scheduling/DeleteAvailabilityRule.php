<?php

namespace App\Domain\Scheduling;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Tenancy\TenantContext;
use App\Models\AvailabilityRule;
use Illuminate\Support\Facades\DB;

/**
 * Removes an availability rule. Nothing references rules (appointments keep
 * their own times), so it is a hard delete; the audit entry keeps what it was.
 */
final class DeleteAvailabilityRule
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(AvailabilityRule $rule): void
    {
        TenantGuard::assertOwned($this->tenant->organizationOrFail()->id, $rule);

        DB::transaction(function () use ($rule) {
            $this->audit->record(
                'availability.rule_deleted',
                subject: $rule,
                before: SaveAvailabilityRule::snapshot($rule, SaveAvailabilityRule::currentServiceIds($rule)),
                summary: 'Availability removed',
            );

            $rule->delete();
        });
    }
}
