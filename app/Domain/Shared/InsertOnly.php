<?php

namespace App\Domain\Shared;

use LogicException;

/**
 * History and audit rows are facts: corrections are new rows. The matching
 * PostgreSQL trigger enforces the same rule for anything that bypasses Eloquent.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait InsertOnly
{
    public static function bootInsertOnly(): void
    {
        static::updating(fn () => throw new LogicException(static::class.' rows are insert-only.'));
        static::deleting(fn () => throw new LogicException(static::class.' rows are insert-only.'));
    }
}
