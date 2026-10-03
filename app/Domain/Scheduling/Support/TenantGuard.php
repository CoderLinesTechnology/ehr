<?php

namespace App\Domain\Scheduling\Support;

use App\Domain\Tenancy\TenantMismatch;
use Illuminate\Database\Eloquent\Model;

/**
 * Scheduling actions receive models from adapters. Each must belong to the
 * organization the action runs in; anything else is a bug or an IDOR attempt,
 * so it fails closed before a single row is read or written. (The composite
 * foreign keys refuse the write anyway; this refuses it earlier and louder.)
 */
final class TenantGuard
{
    public static function assertOwned(string $organizationId, ?Model ...$models): void
    {
        foreach ($models as $model) {
            if ($model === null) {
                continue;
            }

            if (($model->getAttributes()['organization_id'] ?? null) !== $organizationId) {
                throw new TenantMismatch('Refusing to use a record that belongs to another organization.');
            }
        }
    }
}
