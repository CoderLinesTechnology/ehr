<?php

namespace App\Domain\Telehealth\Daily;

use App\Domain\Telehealth\Providers\VideoServiceException;

/**
 * Daily answered with an error (`{"error": "<stable type>", "info": "<text>"}`), or could not be reached. Only the
 * stable `error` type and the HTTP status are kept: the `info` text, the body, the key and tokens never are.
 */
final class DailyException extends VideoServiceException
{
    public static function notConfigured(): self
    {
        return new self('not-configured');
    }

    public static function unreachable(): self
    {
        return new self('connection-error');
    }

    public static function invalidResponse(): self
    {
        return new self('invalid-response');
    }

    public static function invalidRequest(): self
    {
        return new self('invalid-request-error', 400);
    }
}
