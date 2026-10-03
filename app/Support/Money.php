<?php

namespace App\Support;

use NumberFormatter;

/**
 * Money is stored as integer minor units plus an ISO 4217 code — never a float.
 * These helpers convert at the edges (forms in, display out).
 */
final class Money
{
    public static function format(int $minor, string $currency, string $locale = 'en'): string
    {
        $digits = Regions::minorUnitDigits($currency);
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $digits);

        $major = $digits === 0 ? $minor : $minor / (10 ** $digits);
        $formatted = $formatter->formatCurrency($major, strtoupper($currency));

        return $formatted === false ? strtoupper($currency).' '.number_format($major, $digits) : $formatted;
    }

    /** "250.50" (a validated decimal string from a form) → 25050. Exact; no float arithmetic. */
    public static function toMinor(string $amount, string $currency): int
    {
        $digits = Regions::minorUnitDigits($currency);
        $amount = trim($amount);

        if (! preg_match('/^\d{1,12}(?:\.(\d{1,'.max($digits, 1).'}))?$/', $amount)) {
            throw new \InvalidArgumentException("Invalid amount [{$amount}].");
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        if ($digits === 0 && trim($fraction, '0') !== '') {
            throw new \InvalidArgumentException("{$currency} has no minor unit.");
        }

        return (int) $whole * (10 ** $digits) + (int) str_pad(substr($fraction, 0, $digits), $digits, '0');
    }

    /** 25050 → "250.50" for a form input. */
    public static function toDecimalString(int $minor, string $currency): string
    {
        $digits = Regions::minorUnitDigits($currency);
        if ($digits === 0) {
            return (string) $minor;
        }

        return intdiv($minor, 10 ** $digits).'.'.str_pad((string) ($minor % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** Validation regex for a decimal amount input in $currency. */
    public static function inputRule(string $currency): string
    {
        $digits = Regions::minorUnitDigits($currency);

        return $digits === 0 ? 'regex:/^\d{1,12}$/' : 'regex:/^\d{1,12}(\.\d{1,'.$digits.'})?$/';
    }
}
