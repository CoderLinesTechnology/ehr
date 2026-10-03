<?php

namespace App\Domain\Resources;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Domain\Organization\AuditDiff;
use App\Domain\Shared\DomainException;
use App\Models\Resource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Creates a resource (a draft) or edits one of the current organization.
 *
 *  - Needs `resources.manage`. Status is never taken from input: PublishResource and ArchiveResource own it.
 *  - Videos are an https link (opened in a new tab, never embedded); everything else is text and/or one PDF.
 *  - The featured flag needs a published resource and at most FEATURED_LIMIT are featured at once; the count
 *    is taken under a lock on the organization row, so two admins cannot both take the last slot.
 *  - A published resource must stay publishable (an edit cannot blank its content).
 *  - A new PDF replaces the old one; the old file is deleted after the transaction commits, the new one is
 *    deleted if it does not.
 *
 * Audit: `resource.created` / `resource.updated` (before/after of the changed fields; never the text itself).
 */
final class SaveResource
{
    public const FEATURED_LIMIT = 4;

    public function __construct(
        private readonly AccessGuard $guard,
        private readonly AuditLogger $audit,
        private readonly ResourceFiles $files,
    ) {}

    /**
     * @param  array<string, mixed>  $input  type, title, summary, body, external_url, reading_minutes, audience, is_featured
     *
     * @throws DomainException
     */
    public function __invoke(array $input, ?UploadedFile $file = null, ?Resource $resource = null, bool $removeFile = false): Resource
    {
        $this->guard->requirePermission('resources.manage');
        $organization = $this->guard->organization();

        if ($resource !== null) {
            $this->guard->assertInOrganization($resource);
        }

        $attributes = $this->attributes($input);
        $type = ResourceType::from($attributes['type']);

        if ($type !== ResourceType::Video && $attributes['external_url'] !== null) {
            throw new DomainException('Only videos link to another website.', 'resource_link', 'external_url');
        }
        if ($type === ResourceType::Video && ($file !== null)) {
            throw new DomainException('A video is a link, not a file.', 'resource_file', 'file');
        }
        if ($file !== null) {
            $this->files->validate($file);
        }
        $wantsFeatured = (bool) ($input['is_featured'] ?? false);

        $stored = null;
        $replaced = null;

        try {
            $saved = DB::transaction(function () use ($organization, $attributes, $type, $file, $resource, $removeFile, $wantsFeatured, &$stored, &$replaced) {
                $this->guard->lockOrganization();

                $locked = $resource === null
                    ? null
                    : Resource::query()->whereKey($resource->id)->lockForUpdate()->firstOrFail();
                $target = $locked ?? new Resource;
                $creating = $locked === null;

                $target->fill($attributes);
                if ($creating) {
                    $target->forceFill(['status' => ResourceStatus::Draft, 'is_featured' => false, 'created_by_user_id' => $this->guard->actor()->user_id]);
                }

                $fileChange = null;
                if ($type === ResourceType::Video || $removeFile) {
                    if ($target->hasFile()) {
                        $replaced = $target->file_path;
                        $fileChange = 'removed';
                    }
                    $target->forceFill(['file_path' => null, 'file_size_bytes' => null]);
                }
                if ($file !== null) {
                    [$path, $size] = $this->files->store($file, $organization->id);
                    $stored = $path;
                    $replaced ??= $target->hasFile() ? $target->getOriginal('file_path') : null;
                    $fileChange = $replaced !== null ? 'replaced' : 'added';
                    $target->forceFill(['file_path' => $path, 'file_size_bytes' => $size]);
                }

                if ($attributes['reading_minutes'] === null) {
                    $target->reading_minutes = $type === ResourceType::Video ? null : ResourceContent::readingMinutes($target->body);
                }

                $this->applyFeatured($target, $wantsFeatured);
                if (! $creating && $target->isPublished()) {
                    ResourceContent::assertPublishable($target);
                }

                if ($creating) {
                    $target->save();
                    $target->refresh();
                    $this->audit->record(
                        'resource.created',
                        $target,
                        after: ['type' => $target->type->value, 'title' => $target->title, 'audience' => $target->audience->value, 'status' => 'draft'],
                        summary: "Created the {$target->type->label()} “{$target->title}”",
                    );
                } else {
                    [$before, $after] = AuditDiff::of($target, ['updated_at', 'body', 'file_path', 'file_size_bytes']);
                    $bodyChanged = $target->isDirty('body');
                    $target->save();
                    if ($after !== [] || $bodyChanged || $fileChange !== null) {
                        $this->audit->record(
                            'resource.updated',
                            $target,
                            $this->plain($before),
                            $this->plain($after),
                            metadata: array_filter(['body_changed' => $bodyChanged ?: null, 'file' => $fileChange]),
                            summary: "Updated the {$target->type->label()} “{$target->title}”",
                        );
                    }
                    $target->refresh();
                }

                return $target;
            });
        } catch (\Throwable $e) {
            $this->files->delete($stored);

            throw $e;
        }

        if ($replaced !== null && $replaced !== $saved->file_path) {
            $this->files->delete($replaced);
        }
        if ($resource !== null) {
            $resource->setRawAttributes($saved->getAttributes(), true);
        }

        return $saved;
    }

    /** The featured flag, enforced under the organization lock. */
    private function applyFeatured(Resource $resource, bool $wants): void
    {
        if (! $wants) {
            $resource->forceFill(['is_featured' => false]);

            return;
        }
        if (! $resource->isPublished()) {
            throw new DomainException('Publish this resource before featuring it.', 'resource_not_published', 'is_featured');
        }
        if (! $resource->is_featured || ! $resource->exists) {
            $taken = Resource::query()->where('is_featured', true)->when($resource->exists, fn ($q) => $q->whereKeyNot($resource->id))->count();
            if ($taken >= self::FEATURED_LIMIT) {
                throw new DomainException('Up to '.self::FEATURED_LIMIT.' resources can be featured. Remove one first.', 'featured_limit', 'is_featured');
            }
        }
        $resource->forceFill(['is_featured' => true]);
    }

    /** @return array<string, mixed> */
    private function attributes(array $input): array
    {
        $type = ResourceType::tryFrom((string) ($input['type'] ?? ''));
        $audience = ResourceAudience::tryFrom((string) ($input['audience'] ?? ResourceAudience::Everyone->value));
        if ($type === null) {
            throw new DomainException('Choose what kind of resource this is.', 'resource_type', 'type');
        }
        if ($audience === null) {
            throw new DomainException('Choose who this is for.', 'resource_audience', 'audience');
        }

        $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['title'] ?? '')));
        $summary = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['summary'] ?? '')));
        if ($title === '' || mb_strlen($title) > 120) {
            throw new DomainException('Give the resource a title of up to 120 characters.', 'resource_title', 'title');
        }
        if ($summary === '' || mb_strlen($summary) > 300) {
            throw new DomainException('Add a short description of up to 300 characters.', 'resource_summary', 'summary');
        }

        $body = ResourceContent::cleanBody(isset($input['body']) ? (string) $input['body'] : null);
        if ($body !== null && mb_strlen($body) > 20000) {
            throw new DomainException('The text can be at most 20,000 characters.', 'resource_body', 'body');
        }

        $url = isset($input['external_url']) ? trim((string) $input['external_url']) : '';
        if ($url !== '' && (mb_strlen($url) > 2048 || preg_match('#^https://[^/@\s]+([/?\#]|$)#i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false)) {
            throw new DomainException('The link must be a full https:// address.', 'resource_url', 'external_url');
        }

        $minutes = $input['reading_minutes'] ?? null;
        $minutes = ($minutes === null || $minutes === '') ? null : (int) $minutes;
        if ($minutes !== null && ($minutes < 1 || $minutes > 600)) {
            throw new DomainException('Reading time must be between 1 and 600 minutes.', 'resource_minutes', 'reading_minutes');
        }

        return [
            'type' => $type->value,
            'title' => $title,
            'summary' => $summary,
            'body' => $body,
            'external_url' => $url === '' ? null : $url,
            'reading_minutes' => $minutes,
            'audience' => $audience->value,
        ];
    }

    /** Enums to their stored values for the audit trail. @param array<string, mixed> $values @return array<string, mixed> */
    private function plain(array $values): array
    {
        return array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $values);
    }
}
