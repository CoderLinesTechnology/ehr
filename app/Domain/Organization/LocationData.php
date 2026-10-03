<?php

namespace App\Domain\Organization;

use App\Domain\Shared\DomainException;
use App\Support\Regions;
use DateTimeZone;

/**
 * Cleans and checks the input of a location. The form request already reported
 * field errors; this is the domain's own guarantee that nothing malformed is stored,
 * whoever calls it.
 */
final class LocationData
{
    /** column => maximum length */
    private const TEXT = [
        'address_line1' => 200, 'address_line2' => 200, 'city' => 120, 'region' => 120,
        'postal_code' => 32, 'phone' => 32, 'email' => 254,
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> fillable attributes (never is_active, never organization_id)
     *
     * @throws DomainException
     */
    public static function attributes(array $input, string $fallbackTimezone): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($input['name'] ?? '')) ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainException('Give the location a name of up to 120 characters.', 'invalid_name', 'name');
        }

        $attributes = ['name' => $name];

        foreach (self::TEXT as $field => $max) {
            $value = trim((string) ($input[$field] ?? ''));
            if (mb_strlen($value) > $max) {
                throw new DomainException('That value is too long.', 'too_long', $field);
            }
            $attributes[$field] = $value === '' ? null : $value;
        }

        if ($attributes['email'] !== null && filter_var($attributes['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('Enter a valid email address.', 'invalid_email', 'email');
        }

        $country = strtoupper(trim((string) ($input['country_code'] ?? '')));
        if ($country !== '' && ! isset(Regions::countries()[$country])) {
            throw new DomainException('Choose a country from the list.', 'invalid_country', 'country_code');
        }
        $attributes['country_code'] = $country === '' ? null : $country;

        $timezone = trim((string) ($input['timezone'] ?? '')) ?: $fallbackTimezone;
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new DomainException('Choose a timezone from the list.', 'invalid_timezone', 'timezone');
        }
        $attributes['timezone'] = $timezone;

        if (array_key_exists('business_hours', $input)) {
            if (! BusinessHours::isValid($input['business_hours'])) {
                throw new DomainException('The opening hours are not valid.', 'invalid_hours', 'hours');
            }
            $attributes['business_hours'] = $input['business_hours'] === [] ? null : $input['business_hours'];
        }

        return $attributes;
    }
}
