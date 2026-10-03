<?php

namespace App\Domain\Organization;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Domain\Shared\DomainException;
use App\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Replaces the organization's logo.
 *
 * The file is judged by its content, never by its name or the browser's content type: it must decode
 * as a PNG or a JPEG, be at most 1 MB and 1024 x 1024 px (checked from the header before any pixel
 * is decoded, so a small file cannot expand into a huge bitmap). It is then re-encoded with GD, which
 * drops everything but the pixels (EXIF/GPS, text chunks, embedded payloads). The result is stored
 * on the private `local` disk as organizations/{id}/logo-{random}.{ext}; the random name makes every
 * logo a new URL (cache busting) and nothing is ever served from a path the client chose.
 * Audit: `organization.logo_updated` (no file contents, no paths).
 */
final class UpdateOrganizationLogo
{
    public const MAX_BYTES = 1_048_576;

    public const MAX_PIXELS = 1024;

    private const TYPES = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'];

    public function __construct(
        private readonly AccessGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws DomainException */
    public function __invoke(Organization $organization, UploadedFile $file): Organization
    {
        $this->guard->requirePermission('organization.settings.manage');

        if ($organization->id !== $this->guard->organization()->id) {
            throw (new ModelNotFoundException)->setModel(Organization::class, [$organization->id]);
        }

        [$bytes, $extension] = $this->clean($file);

        $disk = Storage::disk('local');
        $path = "organizations/{$organization->id}/logo-".Str::lower(Str::random(24)).'.'.$extension;

        $disk->put($path, $bytes);

        try {
            $previous = DB::transaction(function () use ($organization, $path) {
                $locked = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
                $previous = $locked->logo_path;

                $locked->forceFill(['logo_path' => $path]);
                $this->audit->record(
                    'organization.logo_updated',
                    $locked,
                    metadata: ['replaced' => $previous !== null],
                    summary: 'Changed the organization logo',
                );
                $locked->save();
                $organization->setRawAttributes($locked->getAttributes(), true);

                return $previous;
            });
        } catch (\Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        if ($previous !== null && $previous !== $path) {
            $disk->delete($previous);
        }

        return $organization;
    }

    /** @return array{0: string, 1: string} re-encoded bytes and the extension */
    private function clean(UploadedFile $file): array
    {
        $fail = static fn (string $message, string $code): DomainException => new DomainException($message, $code, 'logo');

        if (! $file->isValid() || ($real = $file->getRealPath()) === false) {
            throw $fail('The file could not be uploaded. Try again.', 'upload_failed');
        }
        $size = filesize($real);
        if ($size === false || $size === 0 || $size > self::MAX_BYTES) {
            throw $fail('The logo must be 1 MB or smaller.', 'logo_too_large');
        }

        $info = @getimagesize($real);
        $type = $info[2] ?? null;
        if ($info === false || ! isset(self::TYPES[$type])) {
            throw $fail('The logo must be a PNG or JPG image.', 'logo_type');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_PIXELS || $info[1] > self::MAX_PIXELS) {
            throw $fail('The logo can be at most '.self::MAX_PIXELS.' x '.self::MAX_PIXELS.' pixels.', 'logo_dimensions');
        }

        $image = @imagecreatefromstring((string) file_get_contents($real));
        if ($image === false) {
            throw $fail('That image could not be read. Export it again as a PNG or JPG.', 'logo_unreadable');
        }

        ob_start();
        if ($type === IMAGETYPE_PNG) {
            imagesavealpha($image, true);
            imagepng($image, null, 9);
        } else {
            imagejpeg($image, null, 90);
        }
        $bytes = (string) ob_get_clean();

        return [$bytes, self::TYPES[$type]];
    }
}
