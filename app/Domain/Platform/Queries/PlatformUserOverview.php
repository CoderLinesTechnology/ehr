<?php

namespace App\Domain\Platform\Queries;

use App\Models\AuditLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The platform's view of one account: profile, which organizations it belongs
 * to (organization NAME, status and role names only), its platform roles, and
 * the platform activity about it. Never what the person does inside an organization.
 */
final readonly class PlatformUserOverview
{
    public function __construct(
        /** @var array{name: string, email: string, disabled: bool, email_verified_at: ?CarbonInterface, two_factor_enabled: bool, timezone: ?string, last_login_at: ?CarbonInterface, created_at: CarbonInterface} */
        public array $profile,
        /** @var list<array{organization: string, slug: string, organization_status: string, status: string, roles: list<string>}> */
        public array $memberships,
        /** @var list<array{key: string, name: string}> */
        public array $platformRoles,
        /** @var Collection<int, AuditLog> platform-visible entries whose subject is this account, newest first */
        public Collection $recentAudit,
    ) {}
}
