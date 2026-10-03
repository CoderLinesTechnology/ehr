<?php

namespace App\Domain\Scheduling\Support;

use App\Domain\Shared\DomainException;

/** Free-text input normalisation for scheduling actions. */
final class Text
{
    /** Trimmed text, null when blank; refuses text longer than $max characters. */
    public static function optional(?string $value, int $max, string $field, string $label): ?string
    {
        $value = $value === null ? null : trim($value);

        if ($value === null || $value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            throw new DomainException("The {$label} may not be longer than {$max} characters.", 'too_long', $field);
        }

        return $value;
    }
}
