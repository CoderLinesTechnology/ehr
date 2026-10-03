<?php

namespace App\Http\Controllers\Platform\Concerns;

use App\Domain\Platform\PlatformAbility;
use App\Domain\Platform\PlatformAuthorizer;

/**
 * Every console action authorizes through PlatformAuthorizer (the platform
 * permission AND confirmed two-factor AND an enabled account), on top of the
 * route's `can:` middleware: a route someone forgets to protect still refuses.
 */
trait AuthorizesPlatform
{
    protected function allow(PlatformAbility $ability): void
    {
        app(PlatformAuthorizer::class)->authorize(request()->user(), $ability);
    }
}
