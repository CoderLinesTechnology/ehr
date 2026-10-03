<?php

namespace App\Domain\Resources;

use App\Domain\Shared\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The only way a resource gets a file on disk, and the only reader of the path.
 *
 * A file is judged by its content, never by its name or the browser's content type: it must start with
 * the PDF header, be identified as application/pdf by libmagic, be at most 20 MB, and carry no script or
 * launch actions (a heuristic over the raw bytes; the file is also served with a fixed type, nosniff and a
 * private cache). It is stored on the private `local` disk as resources/{organization}/{random}.pdf, so
 * nothing is ever served from a path the client chose.
 */
final class ResourceFiles
{
    public const MAX_BYTES = 20_971_520;

    /** Active content a reference PDF has no use for. */
    private const FORBIDDEN = ['/JavaScript', '/JS', '/Launch', '/RichMedia', '/EmbeddedFile'];

    /** @throws DomainException */
    public function validate(UploadedFile $file): string
    {
        $fail = static fn (string $message, string $code): DomainException => new DomainException($message, $code, 'file');

        if (! $file->isValid() || ($real = $file->getRealPath()) === false) {
            throw $fail('The file could not be uploaded. Try again.', 'upload_failed');
        }
        $size = filesize($real);
        if ($size === false || $size === 0 || $size > self::MAX_BYTES) {
            throw $fail('The PDF must be 20 MB or smaller.', 'file_too_large');
        }

        $handle = fopen($real, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, 5);
        if ($handle !== false) {
            fclose($handle);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($real);
        if ($head !== '%PDF-' || $mime !== 'application/pdf') {
            throw $fail('Only PDF files can be attached.', 'file_type');
        }
        if ($this->hasActiveContent($real)) {
            throw $fail('This PDF contains scripts or embedded programs. Export a plain copy and upload that.', 'file_active_content');
        }

        return $real;
    }

    /** @return array{0: string, 1: int} stored path and size in bytes */
    public function store(UploadedFile $file, string $organizationId): array
    {
        $real = $this->validate($file);
        $path = "resources/{$organizationId}/".Str::lower(Str::random(32)).'.pdf';

        $stream = fopen($real, 'rb');
        try {
            Storage::disk('local')->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [$path, (int) filesize($real)];
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk('local')->delete($path);
        }
    }

    /** True only for a path that belongs to this organization's folder (defence against a tampered row). */
    public static function isOwnPath(?string $path, string $organizationId): bool
    {
        return is_string($path)
            && str_starts_with($path, "resources/{$organizationId}/")
            && ! str_contains($path, '..')
            && str_ends_with($path, '.pdf');
    }

    private function hasActiveContent(string $real): bool
    {
        $handle = fopen($real, 'rb');
        if ($handle === false) {
            return true;
        }
        $carry = '';
        try {
            while (! feof($handle)) {
                $chunk = $carry.(string) fread($handle, 1_048_576);
                foreach (self::FORBIDDEN as $needle) {
                    if (str_contains($chunk, $needle)) {
                        return true;
                    }
                }
                $carry = substr($chunk, -16);
            }
        } finally {
            fclose($handle);
        }

        return false;
    }
}
