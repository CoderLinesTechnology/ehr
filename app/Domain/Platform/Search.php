<?php

namespace App\Domain\Platform;

/**
 * Safe search terms for the platform read services. A term typed by a person is
 * data, never a pattern: the wildcard characters in it are escaped so that
 * "50%" or "a_b" search for exactly that, and an administrator cannot turn a
 * search box into a scan of the whole table with a lone "%".
 */
final class Search
{
    public const MAX_LENGTH = 100;

    /** Trim, collapse to a plain string and cap the length. Anything that is not a string is "no term". */
    public static function clean(mixed $term, int $max = self::MAX_LENGTH): string
    {
        return is_string($term) ? mb_substr(trim($term), 0, $max) : '';
    }

    /** `%term%` for LIKE / ILIKE. */
    public static function contains(string $term): string
    {
        return '%'.self::escape($term).'%';
    }

    /** `term%` for LIKE / ILIKE. */
    public static function prefix(string $term): string
    {
        return self::escape($term).'%';
    }

    /** Escape the LIKE metacharacters (PostgreSQL's default escape character is the backslash). */
    public static function escape(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
