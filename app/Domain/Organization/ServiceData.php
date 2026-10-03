<?php

namespace App\Domain\Organization;

use App\Domain\Shared\DomainException;
use App\Support\Money;

/**
 * Cleans and checks the input of a service. Prices arrive as decimal strings ("250.50") and
 * leave as integer minor units in the currency passed in (the organization's for a new service,
 * the service's own for an existing one): no float ever touches money.
 */
final class ServiceData
{
    public const BILLING = ['billable', 'non_billable'];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> fillable attributes (never currency, is_active or organization_id)
     *
     * @throws DomainException
     */
    public static function attributes(array $input, string $currency): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($input['name'] ?? '')) ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainException('Give the service a name of up to 120 characters.', 'invalid_name', 'name');
        }

        $description = trim((string) ($input['description'] ?? ''));
        if (mb_strlen($description) > 2000) {
            throw new DomainException('The description is too long (2000 characters at most).', 'too_long', 'description');
        }

        $code = trim((string) ($input['code'] ?? ''));
        if (mb_strlen($code) > 20) {
            throw new DomainException('The code can be up to 20 characters.', 'too_long', 'code');
        }

        $duration = filter_var($input['duration_minutes'] ?? null, FILTER_VALIDATE_INT);
        if ($duration === false || $duration < 5 || $duration > 1440) {
            throw new DomainException('The duration must be between 5 and 1440 minutes.', 'invalid_duration', 'duration_minutes');
        }

        $inPerson = self::bool($input['allows_in_person'] ?? false);
        $telehealth = self::bool($input['allows_telehealth'] ?? false);
        if (! $inPerson && ! $telehealth) {
            throw new DomainException('Offer the service in person, by telehealth, or both.', 'no_modality', 'allows_in_person');
        }

        $billing = (string) ($input['billing_behavior'] ?? 'billable');
        if (! in_array($billing, self::BILLING, true)) {
            throw new DomainException('Choose how the service is billed.', 'invalid_billing', 'billing_behavior');
        }

        $notice = $input['cancellation_notice_hours'] ?? null;
        if ($notice === null || $notice === '') {
            $notice = null;
        } else {
            $notice = filter_var($notice, FILTER_VALIDATE_INT);
            if ($notice === false || $notice < 0 || $notice > 168) {
                throw new DomainException('The cancellation notice must be between 0 and 168 hours.', 'invalid_notice', 'cancellation_notice_hours');
            }
        }

        $color = trim((string) ($input['color'] ?? ''));
        if ($color !== '' && ! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            throw new DomainException('Choose a colour like #5b8def.', 'invalid_color', 'color');
        }

        return [
            'name' => $name,
            'description' => $description === '' ? null : $description,
            'code' => $code === '' ? null : $code,
            'duration_minutes' => $duration,
            'price_minor' => self::minor($input['price'] ?? null, $currency, 'price', required: true),
            'late_cancellation_fee_minor' => self::minor($input['late_cancellation_fee'] ?? null, $currency, 'late_cancellation_fee'),
            'no_show_fee_minor' => self::minor($input['no_show_fee'] ?? null, $currency, 'no_show_fee'),
            'allows_in_person' => $inPerson,
            'allows_telehealth' => $telehealth,
            'is_bookable_online' => self::bool($input['is_bookable_online'] ?? false),
            'billing_behavior' => $billing,
            'requires_documentation' => self::bool($input['requires_documentation'] ?? false),
            'cancellation_notice_hours' => $notice,
            'color' => $color === '' ? null : strtolower($color),
        ];
    }

    private static function minor(mixed $amount, string $currency, string $field, bool $required = false): ?int
    {
        $amount = trim((string) $amount);

        if ($amount === '') {
            if ($required) {
                throw new DomainException('Enter a price (0 if the service is free).', 'invalid_price', $field);
            }

            return null;
        }

        try {
            return Money::toMinor($amount, $currency);
        } catch (\InvalidArgumentException) {
            throw new DomainException("Enter the amount as a plain number in {$currency}, for example 250.00.", 'invalid_price', $field);
        }
    }

    private static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
