<?php

namespace App\Domain\Telehealth\Providers;

use RuntimeException;

/**
 * The video vendor refused or could not be reached. Carries only the vendor's stable error type and the HTTP
 * status — never the request, the response body, the API key or a token — so it is safe to log.
 */
class VideoServiceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorType,
        public readonly int $status = 0,
    ) {
        parent::__construct("Video service request failed ({$errorType}".($status > 0 ? ", HTTP {$status}" : '').').');
    }

    public function notFound(): bool
    {
        return $this->status === 404 || $this->errorType === 'not-found';
    }
}
