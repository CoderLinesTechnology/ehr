<?php

namespace App\Domain\Tenancy;

use LogicException;

/** A tenant-scoped model was queried outside any organization context. */
final class MissingTenantContext extends LogicException
{
    public function __construct(string $message = 'No organization context is set; tenant-scoped data cannot be read or written.')
    {
        parent::__construct($message);
    }
}
