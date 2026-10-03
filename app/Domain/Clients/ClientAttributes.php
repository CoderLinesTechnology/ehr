<?php

namespace App\Domain\Clients;

use App\Domain\Shared\DomainException;
use App\Models\Client;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Support\PhoneNumbers;
use App\Support\Regions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Turns submitted client details into what is stored: only mass-assignable
 * keys, text trimmed (blank = NULL), the phone number in E.164, the e-mail
 * lower-cased, and the values the schema cannot express checked (date of
 * birth not in the future, the clinician an active provider, ...).
 *
 * Shared by CreateClient and UpdateClient so both hold the same line. The
 * form request already reports problems per field; this is the guard for
 * every other caller.
 */
final class ClientAttributes
{
    /**
     * @param  array<string, mixed>  $input
     * @param  Client|null  $current  the client being edited: values it already holds stay acceptable
     *                                even when they no longer would be for a new assignment
     * @return array<string, mixed> only keys present in $input
     *
     * @throws DomainException
     */
    public function __invoke(array $input, Organization $organization, ?Client $current = null): array
    {
        $data = Arr::only($input, (new Client)->getFillable());

        foreach ($data as $key => $value) {
            $data[$key] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }

        if (array_key_exists('email', $data) && $data['email'] !== null) {
            $data['email'] = mb_strtolower($data['email']);
            if (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
                throw new DomainException('Enter a valid email address.', 'invalid_email', 'email');
            }
        }

        if (array_key_exists('phone', $data) && $data['phone'] !== null) {
            $data['phone'] = PhoneNumbers::normalize($data['phone'], $organization->country_code)
                ?? throw new DomainException(self::phoneMessage($organization), 'invalid_phone', 'phone');
        }

        if (array_key_exists('date_of_birth', $data) && $data['date_of_birth'] !== null) {
            $data['date_of_birth'] = $this->dateOfBirth($data['date_of_birth'], $organization);
        }

        $this->assertOption($data, 'sex', ClientSex::values(), 'Choose one of the listed options for sex.');
        $this->assertOption($data, 'preferred_contact_method', ContactMethod::values(), 'Choose one of the listed contact methods.');

        if (array_key_exists('country_code', $data) && $data['country_code'] !== null) {
            $data['country_code'] = strtoupper($data['country_code']);
            if (! isset(Regions::countries()[$data['country_code']])) {
                throw new DomainException('Choose a country from the list.', 'invalid_country', 'country_code');
            }
        }

        if (array_key_exists('timezone', $data) && $data['timezone'] !== null && ! in_array($data['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            throw new DomainException('Choose a time zone from the list.', 'invalid_timezone', 'timezone');
        }

        $this->assertClinician($data, $current);
        $this->assertLocation($data, $current);

        return $data;
    }

    public static function phoneMessage(Organization $organization): string
    {
        return PhoneNumbers::supportsLocalFormat($organization->country_code)
            ? 'That phone number does not look right. Check the digits, or start with + and the country code.'
            : 'Enter the phone number with its country code, starting with +, for example +233 24 410 0001.';
    }

    private function dateOfBirth(mixed $value, Organization $organization): string
    {
        // Strict Y-m-d, and a date that exists (1990-02-30 would otherwise roll over to March).
        $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value)
            : null;

        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw new DomainException('Enter the date of birth as a valid date.', 'invalid_date_of_birth', 'date_of_birth');
        }

        if ($date->toDateString() > CarbonImmutable::now($organization->timezone)->toDateString()) {
            throw new DomainException('The date of birth cannot be in the future.', 'future_date_of_birth', 'date_of_birth');
        }

        if ($date->year < 1900) {
            throw new DomainException('Enter a valid date of birth.', 'invalid_date_of_birth', 'date_of_birth');
        }

        return $date->toDateString();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     */
    private function assertOption(array $data, string $key, array $allowed, string $message): void
    {
        if (($data[$key] ?? null) !== null && ! in_array($data[$key], $allowed, true)) {
            throw new DomainException($message, 'invalid_'.$key, $key);
        }
    }

    /** @param array<string, mixed> $data */
    private function assertClinician(array $data, ?Client $current): void
    {
        $id = $data['primary_clinician_membership_id'] ?? null;

        if ($id === null || $id === $current?->primary_clinician_membership_id) {
            return;
        }

        if (! is_string($id) || ! Str::isUuid($id) || ! OrganizationMembership::query()->providers()->whereKey($id)->exists()) {
            throw new DomainException('Choose an active clinician from the list.', 'invalid_clinician', 'primary_clinician_membership_id');
        }
    }

    /** @param array<string, mixed> $data */
    private function assertLocation(array $data, ?Client $current): void
    {
        $id = $data['primary_location_id'] ?? null;

        if ($id === null || $id === $current?->primary_location_id) {
            return;
        }

        if (! is_string($id) || ! Str::isUuid($id) || ! Location::query()->active()->whereKey($id)->exists()) {
            throw new DomainException('Choose an active location from the list.', 'invalid_location', 'primary_location_id');
        }
    }
}
