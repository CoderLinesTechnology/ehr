<?php

namespace App\Domain\Organization;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Domain\Shared\DomainException;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Activates or deactivates a service. Existing appointments keep it; a deactivated service
 * simply cannot be chosen for new ones.
 */
final class ChangeServiceStatus
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly AuditLogger $audit,
        private readonly RefreshOnboardingStatus $onboarding,
    ) {}

    /** @throws DomainException */
    public function __invoke(Service $service, bool $active): Service
    {
        $this->guard->requirePermission('services.manage');
        $this->guard->assertInOrganization($service);
        $organization = $this->guard->organization();

        DB::transaction(function () use ($service, $active, $organization) {
            $locked = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_active === $active) {
                return;
            }

            $locked->forceFill(['is_active' => $active])->save();

            $this->audit->record(
                $active ? 'service.activated' : 'service.deactivated',
                $locked,
                before: ['is_active' => ! $active],
                after: ['is_active' => $active],
                summary: ($active ? 'Activated' : 'Deactivated')." the service {$locked->name}",
            );

            ($this->onboarding)($organization);
            $service->setRawAttributes($locked->getAttributes(), true);
        });

        return $service;
    }
}
