<?php

namespace App\Domain\Shared;

use RuntimeException;

/**
 * A business rule refused an operation. userMessage() is safe to show to the
 * person who attempted it; anything else that goes wrong renders as a generic error.
 */
class DomainException extends RuntimeException
{
    public function __construct(
        private readonly string $userMessage,
        private readonly string $errorCode = 'domain_error',
        private readonly ?string $field = null,
    ) {
        parent::__construct($userMessage);
    }

    public function userMessage(): string
    {
        return $this->userMessage;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** Form field the message belongs to, when there is one. */
    public function field(): ?string
    {
        return $this->field;
    }
}
