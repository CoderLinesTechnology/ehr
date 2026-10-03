<?php

namespace App\Http\Requests\Settings;

use App\Domain\Settings\SettingDefinition;
use App\Domain\Settings\SettingsRegistry;
use App\Http\Requests\Settings\Concerns\ValidatesRegistrySettings;
use App\Models\Organization;
use App\Support\Regions;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Every form on Settings → Organization posts here with a `section`; only that section's fields are
 * validated and everything else keeps its current value (UpdateOrganizationProfile replaces the whole
 * profile, so `profile()` lays the submitted fields over the stored ones).
 *
 *  profile  name, legal name, tagline          (Organization Profile "Edit")
 *  contact  email, phone, website, address     (Contact Information "Edit")
 *  general  timezone, currency, language, description, date/time format, week start and the two brand colours
 *
 * Authorization is the route's `can:organization.settings.manage`; the domain action checks it again.
 */
final class UpdateOrganizationRequest extends FormRequest
{
    use ValidatesRegistrySettings;

    public const SECTIONS = ['profile', 'contact', 'general'];

    private const PROFILE_FIELDS = [
        'profile' => ['name', 'legal_name', 'tagline'],
        'contact' => ['email', 'phone', 'website', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country_code'],
        'general' => ['timezone', 'currency', 'locale', 'description'],
    ];

    private const SETTING_KEYS = [
        'general' => ['general.date_format', 'general.time_format', 'general.week_starts_on', 'branding.primary_color', 'branding.secondary_color'],
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function section(): string
    {
        $section = $this->input('section');

        return is_string($section) && in_array($section, self::SECTIONS, true) ? $section : 'general';
    }

    /** @return array<string, SettingDefinition> */
    private function definitions(): array
    {
        return collect(self::SETTING_KEYS[$this->section()] ?? [])
            ->mapWithKeys(fn (string $key) => [$key => SettingsRegistry::get($key)])
            ->all();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $all = [
            'name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()\-.]{4,32}$/'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country_code' => ['required', 'string', Rule::in(array_keys(Regions::countries()))],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'currency' => ['required', 'string', Rule::in(array_keys(Regions::currencies()))],
            'locale' => ['required', 'string', Rule::in(array_keys(SettingsRegistry::get('platform.default_locale')->options))],
        ];

        $rules = ['section' => ['required', Rule::in(self::SECTIONS)]]
            + array_intersect_key($all, array_flip(self::PROFILE_FIELDS[$this->section()]));

        return $this->definitions() === [] ? $rules : $rules + $this->settingRules($this->definitions());
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter the name of your organization.',
            'email.email' => 'Enter a valid email address, like name@example.com.',
            'phone.regex' => 'Enter a phone number using digits, spaces, + ( ) - or .',
            'website.url' => 'Enter a full web address starting with https://',
            'country_code.in' => 'Choose a country from the list.',
            'timezone.in' => 'Choose a timezone from the list.',
            'currency.in' => 'Choose a currency from the list.',
            'locale.in' => 'Choose a language from the list.',
            'description.max' => 'The description can be up to 500 characters.',
            '*.max' => 'The :attribute is too long: use at most :max characters.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->settingAttributes($this->definitions()) + [
            'legal_name' => 'legal name',
            'address_line1' => 'address',
            'address_line2' => 'address line 2',
            'postal_code' => 'postal code',
            'country_code' => 'country',
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->rejectUnknownSettings($validator, $this->definitions())];
    }

    /**
     * The whole profile for the domain action: the stored values with this section's submitted fields on top.
     *
     * @return array<string, mixed>
     */
    public function profile(Organization $current): array
    {
        $stored = $current->only([
            'name', 'legal_name', 'tagline', 'description', 'email', 'phone', 'website', 'address_line1', 'address_line2',
            'city', 'region', 'postal_code', 'country_code', 'timezone', 'currency', 'locale',
        ]);

        return array_merge($stored, $this->safe()->only(self::PROFILE_FIELDS[$this->section()]));
    }

    /** @return array<string, mixed> general.* / branding.* setting key => value */
    public function formats(): array
    {
        return $this->definitions() === [] ? [] : $this->settingValuesFor($this->definitions());
    }
}
