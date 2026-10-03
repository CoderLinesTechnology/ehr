<?php

namespace App\Domain\Resources;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Domain\Shared\DomainException;
use App\Models\Resource;
use Illuminate\Support\Facades\DB;

/**
 * Draft or archived → published. The publication time is stamped the first time and kept when a resource
 * is restored from the archive. A resource without its content cannot be published. Needs `resources.manage`.
 * Audit: `resource.published`.
 */
final class PublishResource
{
    public function __construct(private readonly AccessGuard $guard, private readonly AuditLogger $audit) {}

    /** @throws DomainException */
    public function __invoke(Resource $resource): Resource
    {
        $this->guard->requirePermission('resources.manage');
        $this->guard->assertInOrganization($resource);

        return DB::transaction(function () use ($resource) {
            $locked = Resource::query()->whereKey($resource->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPublished()) {
                ResourceContent::assertPublishable($locked);

                $before = $locked->status->value;
                $locked->forceFill(['status' => ResourceStatus::Published, 'published_at' => $locked->published_at ?? now()]);
                $locked->save();

                $this->audit->record(
                    'resource.published',
                    $locked,
                    before: ['status' => $before],
                    after: ['status' => 'published'],
                    summary: "Published “{$locked->title}”",
                );
            }

            $resource->setRawAttributes($locked->getAttributes(), true);

            return $resource;
        });
    }
}
