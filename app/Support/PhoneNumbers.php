<?php

namespace App\Support;

/**
 * Phone numbers are stored in E.164 (+233244100001) and typed, shown and
 * searched the way people actually write them (024 410 0001).
 *
 * There is deliberately no phone library: only countries whose national
 * format is well known are rewritten from their local form; for every other
 * country the number must be entered with a leading "+" (or "00").
 */
final class PhoneNumbers
{
    /**
     * ISO 3166-1 country => calling code, national trunk prefix (the dialling
     * zero), allowed length of the national significant number (the digits
     * after the trunk prefix) and how to group it for display.
     *
     * @var array<string, array{cc: string, trunk: ?string, min: int, max: int, groups: list<int>}>
     */
    private const COUNTRIES = [
        'GH' => ['cc' => '233', 'trunk' => '0', 'min' => 9, 'max' => 9, 'groups' => [2, 3, 4]],
        'NG' => ['cc' => '234', 'trunk' => '0', 'min' => 7, 'max' => 10, 'groups' => [3, 3, 4]],
        'KE' => ['cc' => '254', 'trunk' => '0', 'min' => 9, 'max' => 9, 'groups' => [3, 3, 3]],
        'UG' => ['cc' => '256', 'trunk' => '0', 'min' => 9, 'max' => 9, 'groups' => [3, 3, 3]],
        'TZ' => ['cc' => '255', 'trunk' => '0', 'min' => 9, 'max' => 9, 'groups' => [3, 3, 3]],
        'RW' => ['cc' => '250', 'trunk' => '0', 'min' => 9, 'max' => 9, 'groups' => [3, 3, 3]],
        'ZA' => ['cc' => '27', 'trunk' => '0', 'min' => 9, 'max' => 9, 'groups' => [2, 3, 4]],
        'EG' => ['cc' => '20', 'trunk' => '0', 'min' => 9, 'max' => 10, 'groups' => [3, 3, 4]],
        'MA' => ['cc' => '212', 'trunk' => '0', 'min' => 9, 'max' => 9, 'groups' => [3, 3, 3]],
        'GB' => ['cc' => '44', 'trunk' => '0', 'min' => 9, 'max' => 10, 'groups' => [4, 6]],
        'IE' => ['cc' => '353', 'trunk' => '0', 'min' => 7, 'max' => 9, 'groups' => [2, 3, 4]],
        'FR' => ['cc' => '33', 'trunk' => '0', 'min' => 9, 'max' => 9, 'groups' => [1, 2, 2, 2, 2]],
        'US' => ['cc' => '1', 'trunk' => null, 'min' => 10, 'max' => 10, 'groups' => [3, 3, 4]],
        'CA' => ['cc' => '1', 'trunk' => null, 'min' => 10, 'max' => 10, 'groups' => [3, 3, 4]],
        'AU' => ['cc' => '61', 'trunk' => '0', 'min' => 9, 'max' => 9, 'groups' => [3, 3, 3]],
        'NZ' => ['cc' => '64', 'trunk' => '0', 'min' => 8, 'max' => 10, 'groups' => [2, 3, 4]],
        'IN' => ['cc' => '91', 'trunk' => '0', 'min' => 10, 'max' => 10, 'groups' => [5, 5]],
        'AE' => ['cc' => '971', 'trunk' => '0', 'min' => 8, 'max' => 9, 'groups' => [2, 3, 4]],
    ];

    /** Separators people type between digits: spaces (incl. non-breaking), dots, dashes (incl. Unicode ones), brackets. */
    private const SEPARATORS = '/[\s\p{Z}().\-\x{2010}-\x{2015}\x{2212}]/u';

    /**
     * To E.164. $country (ISO alpha-2, normally the organization's) lets a
     * local number such as 0244 100 001 be completed; a number that already
     * starts with + or 00 is accepted as typed, minus separators.
     *
     * Returns null when the input is blank OR cannot be understood: check for
     * blankness first when the two must be told apart.
     */
    public static function normalize(?string $input, ?string $country = null): ?string
    {
        $clean = self::clean($input);
        if ($clean === null) {
            return null;
        }

        if ($clean[0] === '+') {
            return self::international(substr($clean, 1));
        }

        if (str_starts_with($clean, '00')) {
            return self::international(substr($clean, 2));
        }

        $rule = $country !== null ? (self::COUNTRIES[strtoupper($country)] ?? null) : null;

        return $rule === null ? null : self::local($clean, $rule);
    }

    /** True for a country whose local numbers can be completed without "+". */
    public static function supportsLocalFormat(?string $country): bool
    {
        return $country !== null && isset(self::COUNTRIES[strtoupper($country)]);
    }

    /**
     * What to match in clients.search_text for a term that is only a phone
     * number, or null when the term is anything else (a letter anywhere
     * leaves names, e-mails and references untouched).
     *
     *   "024 410 0001" → "244100001"   (national significant number: the stored
     *                                    +233244100001 contains it)
     *   "+233 24 410"  → "+23324410"
     *   "00233244"     → "+233244"
     */
    public static function searchNeedle(string $term): ?string
    {
        $term = trim($term);
        if ($term === '' || preg_match('/^\+?[\d\s\p{Z}().\-\x{2010}-\x{2015}\x{2212}]+$/u', $term) !== 1) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $term) ?? '';
        if (strlen($digits) < 3) {
            return null;
        }

        return match (true) {
            $term[0] === '+' => '+'.$digits,
            str_starts_with($digits, '00') => '+'.substr($digits, 2),
            $digits[0] === '0' => substr($digits, 1),
            default => $digits,
        };
    }

    /**
     * Human format of a stored number: the viewer's own national format
     * (024 410 0001) for numbers of their country, international otherwise
     * (+44 7911 123456). Anything that is not E.164 is returned as stored.
     * A country is only recognised when the digits fit its national format.
     */
    public static function display(?string $stored, ?string $viewerCountry = null): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }

        if (preg_match('/^\+([1-9]\d{7,14})$/', $stored, $m) !== 1) {
            return $stored;
        }

        $digits = $m[1];
        $viewerCc = $viewerCountry !== null ? (self::COUNTRIES[strtoupper($viewerCountry)]['cc'] ?? null) : null;

        foreach (self::COUNTRIES as $rule) {
            if (! str_starts_with($digits, $rule['cc'])) {
                continue;
            }

            $nsn = substr($digits, strlen($rule['cc']));
            if (! self::fits($nsn, $rule)) {
                continue;
            }

            $grouped = self::group($nsn, $rule['groups']);

            // The viewer's own country: how they dial it locally (024 410 0001, or 202 555 0123 where there is no trunk zero).
            return $viewerCc === $rule['cc']
                ? ($rule['trunk'] ?? '').$grouped
                : '+'.$rule['cc'].' '.$grouped;
        }

        return $stored;
    }

    /** Digits (and one leading +) only, or null for blank input or anything else. */
    private static function clean(?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }

        $input = preg_replace(self::SEPARATORS, '', $input);

        return ($input !== null && preg_match('/^\+?\d+$/', $input) === 1) ? $input : null;
    }

    /** E.164: no leading zero, 8–15 digits including the country code. */
    private static function international(string $digits): ?string
    {
        return preg_match('/^[1-9]\d{7,14}$/', $digits) === 1 ? '+'.$digits : null;
    }

    /** @param array{cc: string, trunk: ?string, min: int, max: int, groups: list<int>} $rule */
    private static function local(string $clean, array $rule): ?string
    {
        // 0244100001 — national form with the trunk zero.
        if ($rule['trunk'] !== null && str_starts_with($clean, $rule['trunk'])) {
            $nsn = substr($clean, strlen($rule['trunk']));

            return self::fits($nsn, $rule) ? '+'.$rule['cc'].$nsn : null;
        }

        // 233244100001 — country code typed without the plus.
        if (str_starts_with($clean, $rule['cc']) && self::fits(substr($clean, strlen($rule['cc'])), $rule)) {
            return '+'.$clean;
        }

        // 244100001 or (202) 555-0123 — the bare national significant number.
        return self::fits($clean, $rule) ? '+'.$rule['cc'].$clean : null;
    }

    /** @param array{cc: string, trunk: ?string, min: int, max: int, groups: list<int>} $rule */
    private static function fits(string $nsn, array $rule): bool
    {
        return $nsn !== ''
            && ctype_digit($nsn)
            && $nsn[0] !== '0'
            && strlen($nsn) >= $rule['min']
            && strlen($nsn) <= $rule['max'];
    }

    /** @param list<int> $groups */
    private static function group(string $nsn, array $groups): string
    {
        $parts = [];
        $offset = 0;
        foreach ($groups as $size) {
            $part = substr($nsn, $offset, $size);
            if ($part === '') {
                break;
            }
            $parts[] = $part;
            $offset += $size;
        }

        if ($offset < strlen($nsn)) {
            $parts[] = substr($nsn, $offset);
        }

        return implode(' ', $parts);
    }
}
