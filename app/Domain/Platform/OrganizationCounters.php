<?php

namespace App\Domain\Platform;

use Illuminate\Support\Facades\DB;

/**
 * Per-organization human-facing sequences (client numbers, invoice numbers).
 * A single upsert statement increments and returns the value atomically, so
 * concurrent callers can never receive the same number. Called inside the
 * caller's transaction, a rollback also rolls the number back (no gaps).
 */
final class OrganizationCounters
{
    public const CLIENT = 'client';

    public function next(string $organizationId, string $key): int
    {
        $row = DB::selectOne(
            'INSERT INTO organization_counters (organization_id, key, value) VALUES (?, ?, 1)
             ON CONFLICT (organization_id, key) DO UPDATE SET value = organization_counters.value + 1
             RETURNING value',
            [$organizationId, $key],
        );

        return (int) $row->value;
    }
}
