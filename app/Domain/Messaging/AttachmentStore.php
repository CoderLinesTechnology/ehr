<?php

namespace App\Domain\Messaging;

use App\Domain\Shared\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Message attachments: PDF, PNG or JPEG up to 10 MB on the private disk. The type is decided by
 * sniffing the bytes, never by the client's file name or declared content type, and the stored
 * name is generated (the original name is kept only as a sanitised display label).
 */
final class AttachmentStore
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const DISK = 'local';

    /** detected mime => extension */
    private const TYPES = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg'];

    /** @return array{mime: string, extension: string, size: int, sha256: string, name: string} */
    public function inspect(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! $file->isValid() || $path === false) {
            throw new DomainException('That file could not be uploaded. Try again.', 'attachment_invalid', 'attachment');
        }

        $size = (int) filesize($path);
        if ($size === 0 || $size > self::MAX_BYTES) {
            throw new DomainException('Attachments must be between 1 byte and 10 MB.', 'attachment_size', 'attachment');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        if (! isset(self::TYPES[$mime]) || ! $this->bytesAgree($path, $mime)) {
            throw new DomainException('Only PDF, PNG and JPEG files can be attached.', 'attachment_type', 'attachment');
        }
        $extension = self::TYPES[$mime];

        $base = pathinfo(Str::of((string) $file->getClientOriginalName())->replace(["\0", "\r", "\n", '/', '\\'], '')->toString(), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\p{L}\p{N} ._()-]+/u', '', $base));
        $name = Str::limit($base === '' ? 'attachment' : $base, 120, '').'.'.$extension;

        return ['mime' => $mime, 'extension' => $extension, 'size' => $size, 'sha256' => hash_file('sha256', $path), 'name' => $name];
    }

    /** Store under a generated path and return it. */
    public function put(UploadedFile $file, string $organizationId, string $conversationId, string $extension): string
    {
        $path = "messages/{$organizationId}/{$conversationId}/".Str::uuid7()->toString().'.'.$extension;
        Storage::disk(self::DISK)->put($path, (string) file_get_contents((string) $file->getRealPath()));

        return $path;
    }

    public function forget(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }

    /** Magic bytes agree with the sniffed type (a PNG header on a JPEG body is refused). */
    private function bytesAgree(string $path, string $mime): bool
    {
        if ($mime === 'application/pdf') {
            return str_starts_with((string) file_get_contents($path, false, null, 0, 5), '%PDF-');
        }

        $info = @getimagesize($path);

        return $info !== false && $info[2] === ($mime === 'image/png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG);
    }
}
