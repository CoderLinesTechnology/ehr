<?php

use App\Domain\Tenancy\TenantContext;
use App\Support\Formatter;

if (! function_exists('fmt')) {
    /** Organization-aware display formatting (dates, times, money). */
    function fmt(): Formatter
    {
        return app(Formatter::class);
    }
}

if (! function_exists('tenant')) {
    /** The current tenant context (organization + acting membership). */
    function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }
}
