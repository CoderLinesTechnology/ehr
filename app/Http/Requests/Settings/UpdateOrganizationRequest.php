<?php

namespace App\Http\Requests\Settings;

use App\Domain\Settings\SettingsRegistry;
use App\Http\Requests\Settings\Concerns\ValidatesRegistrySettings;
use App\Support\Regions;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The organization profile form: profile fields plus the regional formats (the `general` settings).
 * Authorization is the route's `can:organization.settings.manage`; UpdateOrganizationProfile
 * checks it again and re-validates the profile.
 */
final class UpdateOrganizationRequest extends FormRequest
{
    use ValidatesRegistrySettings;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:200'],
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
        ] + $this->settingRules($this->organizationSettings('general'));
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
            '*.max' => 'The :attribute is too long: use at most :max characters.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->settingAttributes($this->organizationSettings('general')) + [
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
        return [fn (Validator $validator) => $this->rejectUnknownSettings($validator, $this->organizationSettings('general'))];
    }

    /** @return array<string, mixed> */
    public function profile(): array
    {
        return $this->safe()->only([
            'name', 'legal_name', 'email', 'phone', 'website', 'address_line1', 'address_line2',
            'city', 'region', 'postal_code', 'country_code', 'timezone', 'currency', 'locale',
        ]);
    }

    /** @return array<string, mixed> general.* setting key => value */
    public function formats(): array
    {
        return $this->settingValuesFor($this->organizationSettings('general'));
    }
}
