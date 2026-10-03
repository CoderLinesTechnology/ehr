<?php

namespace App\Domain\Programs;

use App\Domain\Shared\DomainException;

/** Free-text normalisation for the Programs actions. */
final class ProgramText
{
    public static function required(?string $value, int $max, string $field, string $label): string
    {
        $value = $value === null ? '' : trim($value);

        if ($value === '') {
            throw new DomainException("Enter the {$label}.", 'required', $field);
        }

        return self::limit($value, $max, $field, $label);
    }

    public static function optional(?string $value, int $max, string $field, string $label): ?string
    {
        $value = $value === null ? null : trim($value);

        return ($value === null || $value === '') ? null : self::limit($value, $max, $field, $label);
    }

    private static function limit(string $value, int $max, string $field, string $label): string
    {
        if (mb_strlen($value) > $max) {
            throw new DomainException("The {$label} may not be longer than {$max} characters.", 'too_long', $field);
        }

        return $value;
    }
}
