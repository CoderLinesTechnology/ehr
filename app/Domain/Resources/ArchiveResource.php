<?php

namespace App\Domain\Resources;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Domain\Shared\DomainException;
use App\Models\Resource;
use Illuminate\Support\Facades\DB;

/**
 * Draft or published → archived: nobody but resource managers sees it, and it stops being featured.
 * Archived resources are never deleted (their audit trail and file stay). Needs `resources.manage`.
 * Audit: `resource.archived`.
 */
final class ArchiveResource
{
    public function __construct(private readonly AccessGuard $guard, private readonly AuditLogger $audit) {}

    /** @throws DomainException */
    public function __invoke(Resource $resource): Resource
    {
        $this->guard->requirePermission('resources.manage');
        $this->guard->assertInOrganization($resource);

        return DB::transaction(function () use ($resource) {
            $locked = Resource::query()->whereKey($resource->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ResourceStatus::Archived) {
                $before = $locked->status->value;
                $locked->forceFill(['status' => ResourceStatus::Archived, 'is_featured' => false]);
                $locked->save();

                $this->audit->record(
                    'resource.archived',
                    $locked,
                    before: ['status' => $before],
                    after: ['status' => 'archived'],
                    summary: "Archived “{$locked->title}”",
                );
            }

            $resource->setRawAttributes($locked->getAttributes(), true);

            return $resource;
        });
    }
}
