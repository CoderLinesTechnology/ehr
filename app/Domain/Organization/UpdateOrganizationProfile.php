<?php

namespace App\Domain\Organization;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\DomainException;
use App\Models\Organization;
use App\Support\Regions;
use DateTimeZone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Edits the organization's own profile (name, contact details, address, country, timezone, currency,
 * locale) and, in the same transaction, its regional display formats (the `general.*` settings, saved
 * through SettingsService so each is validated against the registry and audited). The profile's audit
 * entry (`organization.profile_updated`) holds the before/after of exactly the fields that changed.
 *
 * Slug and status are not part of the profile (platform actions write them).
 * Changing the timezone or currency affects only what happens next: appointments keep the timezone they
 * were booked in and services keep the currency they were priced in. The returned list of changed
 * fields lets the caller say so.
 */
final class UpdateOrganizationProfile
{
    /** column => maximum length (name, country, timezone, currency and locale are checked separately) */
    private const TEXT = [
        'legal_name' => 200, 'email' => 254, 'phone' => 32, 'website' => 255,
        'address_line1' => 200, 'address_line2' => 200, 'city' => 120, 'region' => 120, 'postal_code' => 32,
    ];

    public function __construct(
        private readonly AccessGuard $guard,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly RefreshOnboardingStatus $onboarding,
    ) {}

    /**
     * @param  array<string, mixed>  $profile  name, legal_name, email, phone, website, address_line1/2, city, region,
     *                                         postal_code, country_code, timezone, currency, locale
     * @param  array<string, mixed>  $regionalFormats  general.* setting key => value (date_format, time_format, week_starts_on)
     * @return list<string> the profile fields that changed (e.g. ["timezone", "currency"])
     *
     * @throws DomainException
     */
    public function __invoke(Organization $organization, array $profile, array $regionalFormats = []): array
    {
        $this->guard->requirePermission('organization.settings.manage');

        if ($organization->id !== $this->guard->organization()->id) {
            throw (new ModelNotFoundException)->setModel(Organization::class, [$organization->id]);
        }

        $attributes = $this->attributes($profile);
        $this->assertGeneralSettings($regionalFormats);
        $changed = [];

        DB::transaction(function () use ($organization, $attributes, $regionalFormats, &$changed) {
            $locked = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $locked->fill($attributes);

            $changed = array_values(array_diff(array_keys($locked->getDirty()), ['updated_at']));
            $this->audit->recordChanges('organization.profile_updated', $locked, summary: 'Updated the organization profile');
            $locked->save();

            if ($regionalFormats !== []) {
                $this->settings->setOrganization($locked, $regionalFormats, Auth::user());
            }

            $organization->setRawAttributes($locked->getAttributes(), true);
            ($this->onboarding)($organization);
        });

        return $changed;
    }

    /** Only the `general` group belongs to this screen; scheduling and client settings have their own. */
    private function assertGeneralSettings(array $values): void
    {
        $allowed = array_keys(SettingsRegistry::forScope('organization', 'general'));

        if (array_diff(array_map('strval', array_keys($values)), $allowed) !== []) {
            throw new DomainException('One of the submitted settings is not recognised.', 'unknown_setting', 'settings');
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function attributes(array $profile): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($profile['name'] ?? '')) ?? '');
        if ($name === '' || mb_strlen($name) > 160) {
            throw new DomainException('Enter the organization name (up to 160 characters).', 'invalid_name', 'name');
        }

        $attributes = ['name' => $name];

        foreach (self::TEXT as $field => $max) {
            $value = trim((string) ($profile[$field] ?? ''));
            if (mb_strlen($value) > $max) {
                throw new DomainException('That value is too long.', 'too_long', $field);
            }
            $attributes[$field] = $value === '' ? null : $value;
        }

        if ($attributes['email'] !== null && filter_var($attributes['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('Enter a valid email address.', 'invalid_email', 'email');
        }
        if ($attributes['website'] !== null && ! preg_match('#^https?://#i', $attributes['website'])) {
            throw new DomainException('The website must start with http:// or https://.', 'invalid_website', 'website');
        }

        $country = strtoupper(trim((string) ($profile['country_code'] ?? '')));
        if (! isset(Regions::countries()[$country])) {
            throw new DomainException('Choose a country from the list.', 'invalid_country', 'country_code');
        }
        $attributes['country_code'] = $country;

        $timezone = trim((string) ($profile['timezone'] ?? ''));
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new DomainException('Choose a timezone from the list.', 'invalid_timezone', 'timezone');
        }
        $attributes['timezone'] = $timezone;

        $currency = strtoupper(trim((string) ($profile['currency'] ?? '')));
        if (! isset(Regions::currencies()[$currency])) {
            throw new DomainException('Choose a currency from the list.', 'invalid_currency', 'currency');
        }
        $attributes['currency'] = $currency;

        $locale = trim((string) ($profile['locale'] ?? 'en'));
        if (! in_array($locale, array_keys(SettingsRegistry::get('platform.default_locale')->options), true)) {
            throw new DomainException('Choose a language from the list.', 'invalid_locale', 'locale');
        }
        $attributes['locale'] = $locale;

        return $attributes;
    }
}
