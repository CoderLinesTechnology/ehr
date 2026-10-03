<?php

namespace App\Domain\Resources;

use App\Domain\Shared\DomainException;
use App\Models\Resource;

/** What a resource must carry before people can read it, and how its reading time is worked out. */
final class ResourceContent
{
    public const WORDS_PER_MINUTE = 200;

    /** @throws DomainException */
    public static function assertPublishable(Resource $resource): void
    {
        if ($resource->type === ResourceType::Video) {
            if (blank($resource->external_url)) {
                throw new DomainException('Add the video link before publishing.', 'resource_incomplete', 'external_url');
            }

            return;
        }

        if (blank($resource->body) && ! $resource->hasFile()) {
            throw new DomainException('Write the text or attach a PDF before publishing.', 'resource_incomplete', 'body');
        }
    }

    /** Whole minutes (at least one) for the text, null when there is none. */
    public static function readingMinutes(?string $body): ?int
    {
        $words = $body === null ? 0 : preg_match_all('/\S+/u', $body);

        return $words > 0 ? max(1, (int) ceil($words / self::WORDS_PER_MINUTE)) : null;
    }

    /** Plain text with paragraphs: LF line endings, no trailing blanks, no more than one blank line in a row. */
    public static function cleanBody(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = (string) preg_replace('/[^\S\n]+\n/u', "\n", $body);
        $body = (string) preg_replace("/\n{3,}/", "\n\n", $body);
        $body = trim($body);

        return $body === '' ? null : $body;
    }
}
