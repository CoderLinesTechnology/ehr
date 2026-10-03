<?php

namespace App\Domain\Organization;

use App\Domain\Shared\DomainException;

/**
 * Weekly opening hours of a location, stored in `locations.business_hours` (jsonb) as
 * {"1": [["08:00","17:00"]], "2": [...]} keyed by ISO weekday (1 = Monday … 7 = Sunday),
 * each day a list of [open, close] windows in the location's own timezone. A day that is
 * absent is closed. A location with every day closed stores NULL ("hours not published").
 *
 * The edit form offers one window per day; the storage format already allows several
 * (for a lunch break) and this class reads and validates that general shape.
 */
final class BusinessHours
{
    /** ISO weekday => name. */
    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    private const TIME = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    /** The starting point offered for a new location: Monday to Friday, 08:00–17:00. */
    public static function suggested(): array
    {
        return [1 => [['08:00', '17:00']], 2 => [['08:00', '17:00']], 3 => [['08:00', '17:00']], 4 => [['08:00', '17:00']], 5 => [['08:00', '17:00']]];
    }

    /**
     * Stored hours → the shape the form edits: every weekday 1..7 with closed/open/close.
     *
     * @param  array<int|string, mixed>|null  $hours
     * @return array<int, array{closed: bool, open: string, close: string}>
     */
    public static function toForm(?array $hours): array
    {
        $form = [];
        foreach (array_keys(self::DAYS) as $day) {
            $windows = $hours[$day] ?? $hours[(string) $day] ?? null;
            $first = is_array($windows) && isset($windows[0]) && is_array($windows[0]) ? $windows[0] : null;

            $form[$day] = $first === null
                ? ['closed' => true, 'open' => '08:00', 'close' => '17:00']
                : ['closed' => false, 'open' => (string) ($first[0] ?? '08:00'), 'close' => (string) ($first[1] ?? '17:00')];
        }

        return $form;
    }

    /**
     * Form input → storage format, or NULL when every day is closed.
     *
     * @param  array<int|string, mixed>|null  $input  weekday => ['closed' => '0|1', 'open' => 'H:i', 'close' => 'H:i']
     * @return array<string, list<array{0: string, 1: string}>>|null
     *
     * @throws DomainException
     */
    public static function fromForm(?array $input): ?array
    {
        $hours = [];

        foreach (self::DAYS as $day => $name) {
            $row = $input[$day] ?? $input[(string) $day] ?? null;
            if (! is_array($row) || self::truthy($row['closed'] ?? false)) {
                continue;
            }

            $open = trim((string) ($row['open'] ?? ''));
            $close = trim((string) ($row['close'] ?? ''));

            if ($open === '' && $close === '') {
                continue;
            }

            if (! preg_match(self::TIME, $open) || ! preg_match(self::TIME, $close) || $open >= $close) {
                throw new DomainException("Opening hours for {$name} are not valid: the closing time must be after the opening time.", 'invalid_hours', 'hours');
            }

            $hours[(string) $day] = [[$open, $close]];
        }

        return $hours === [] ? null : $hours;
    }

    /** Is this a well-formed stored value (or NULL)? */
    public static function isValid(mixed $hours): bool
    {
        if ($hours === null) {
            return true;
        }

        if (! is_array($hours)) {
            return false;
        }

        foreach ($hours as $day => $windows) {
            if (! isset(self::DAYS[(int) $day]) || ! is_array($windows) || $windows === [] || ! array_is_list($windows)) {
                return false;
            }

            $previousClose = null;
            foreach ($windows as $window) {
                if (! is_array($window) || count($window) !== 2 || ! array_is_list($window)) {
                    return false;
                }
                [$open, $close] = $window;
                if (! is_string($open) || ! is_string($close) || ! preg_match(self::TIME, $open) || ! preg_match(self::TIME, $close) || $open >= $close) {
                    return false;
                }
                if ($previousClose !== null && $open < $previousClose) {
                    return false;
                }
                $previousClose = $close;
            }
        }

        return true;
    }

    /** A short human summary for a list row: "Mon–Fri 08:00–17:00" or "Hours not set". */
    public static function summary(?array $hours): string
    {
        if ($hours === null || $hours === []) {
            return 'Hours not set';
        }

        $groups = [];
        foreach (self::DAYS as $day => $name) {
            $windows = $hours[$day] ?? $hours[(string) $day] ?? null;
            if (! is_array($windows) || $windows === []) {
                continue;
            }
            $text = implode(', ', array_map(fn ($w) => $w[0].'–'.$w[1], $windows));
            $last = array_key_last($groups);
            if ($last !== null && $groups[$last]['text'] === $text && $groups[$last]['to'] === $day - 1) {
                $groups[$last]['to'] = $day;
            } else {
                $groups[] = ['from' => $day, 'to' => $day, 'text' => $text];
            }
        }

        return implode('; ', array_map(function (array $g) {
            $from = substr(self::DAYS[$g['from']], 0, 3);
            $to = substr(self::DAYS[$g['to']], 0, 3);

            return ($g['from'] === $g['to'] ? $from : "{$from}–{$to}").' '.$g['text'];
        }, $groups));
    }

    private static function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
