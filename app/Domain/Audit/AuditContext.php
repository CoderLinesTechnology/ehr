<?php

namespace App\Domain\Audit;

enum AuditContext: string
{
    case Platform = 'platform';
    case Organization = 'organization';
    case Portal = 'portal';
    case Public = 'public';
    case System = 'system';
}
