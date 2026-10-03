<?php

namespace App\Domain\Organization;

use Illuminate\Database\Eloquent\Model;

/**
 * The before/after of a model's pending changes, as cast values (booleans, arrays) rather than the
 * raw column strings AuditLogger::recordChanges() would store. Call BEFORE save(), like recordChanges.
 */
final class AuditDiff
{
    /**
     * @param  list<string>  $ignore
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [before, after]
     */
    public static function of(Model $model, array $ignore = ['updated_at']): array
    {
        $before = [];
        $after = [];

        foreach (array_keys($model->getDirty()) as $key) {
            if (in_array($key, $ignore, true)) {
                continue;
            }
            $before[$key] = $model->getOriginal($key);
            $after[$key] = $model->getAttribute($key);
        }

        return [$before, $after];
    }
}
