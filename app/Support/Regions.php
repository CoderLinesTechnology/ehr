<?php

namespace App\Support;

use DateTimeZone;
use Locale;

/**
 * Country, currency and timezone option lists, derived from the runtime's
 * tz database and ICU data rather than hard-coded per market.
 */
final class Regions
{
    /** @var array<string, string>|null */
    private static ?array $countries = null;

    /** @return array<string, string> ISO 3166-1 alpha-2 => English name, sorted by name */
    public static function countries(): array
    {
        if (self::$countries !== null) {
            return self::$countries;
        }

        $codes = [];
        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $code = (new DateTimeZone($identifier))->getLocation()['country_code'] ?? '??';
            if ($code !== '??' && preg_match('/^[A-Z]{2}$/', $code)) {
                $codes[$code] = true;
            }
        }

        $countries = [];
        foreach (array_keys($codes) as $code) {
            $name = Locale::getDisplayRegion('-'.$code, 'en');
            $countries[$code] = $name !== '' && $name !== $code ? $name : $code;
        }
        asort($countries, SORT_NATURAL | SORT_FLAG_CASE);

        return self::$countries = $countries;
    }

    /** @return array<string, string> ISO 4217 => label */
    public static function currencies(): array
    {
        return [
            'GHS' => 'GHS — Ghanaian cedi',
            'USD' => 'USD — US dollar',
            'EUR' => 'EUR — Euro',
            'GBP' => 'GBP — Pound sterling',
            'NGN' => 'NGN — Nigerian naira',
            'KES' => 'KES — Kenyan shilling',
            'ZAR' => 'ZAR — South African rand',
            'UGX' => 'UGX — Ugandan shilling',
            'TZS' => 'TZS — Tanzanian shilling',
            'RWF' => 'RWF — Rwandan franc',
            'XOF' => 'XOF — West African CFA franc',
            'XAF' => 'XAF — Central African CFA franc',
            'EGP' => 'EGP — Egyptian pound',
            'MAD' => 'MAD — Moroccan dirham',
            'CAD' => 'CAD — Canadian dollar',
            'AUD' => 'AUD — Australian dollar',
            'NZD' => 'NZD — New Zealand dollar',
            'INR' => 'INR — Indian rupee',
            'AED' => 'AED — UAE dirham',
            'JMD' => 'JMD — Jamaican dollar',
        ];
    }

    /** Currencies with no minor unit (amounts are stored in whole units). */
    public static function minorUnitDigits(string $currency): int
    {
        return in_array(strtoupper($currency), ['UGX', 'RWF', 'XOF', 'XAF', 'JPY', 'KRW'], true) ? 0 : 2;
    }

    /** @return array<string, array<string, string>> region => [identifier => label] for grouped selects */
    public static function timezones(): array
    {
        $grouped = [];
        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $region = str_contains($identifier, '/') ? strstr($identifier, '/', true) : 'Other';
            $grouped[$region][$identifier] = str_replace(['_', '/'], [' ', ' / '], $identifier);
        }

        return $grouped;
    }

    public static function defaultTimezoneFor(string $countryCode): ?string
    {
        return DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, strtoupper($countryCode))[0] ?? null;
    }
}
