<?php

namespace App\Http\Requests\Platform;

use App\Domain\Platform\OrganizationStatus;
use App\Support\Regions;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('platform.organizations.manage') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'country_code' => ['required', 'string', Rule::in(array_keys(Regions::countries()))],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'currency' => ['required', 'string', Rule::in(array_keys(Regions::currencies()))],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:32'],
            'plan_id' => ['bail', 'required', 'uuid', Rule::exists('plans', 'id')->where('is_active', true)],
            'status' => ['required', Rule::in([OrganizationStatus::Pending->value, OrganizationStatus::Trial->value, OrganizationStatus::Active->value])],
            'owner_email' => ['required', 'email:rfc', 'max:254'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The address may only contain lowercase letters, numbers and hyphens, and cannot start or end with a hyphen.',
            'plan_id.exists' => 'Choose one of the available plans.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['plan_id' => 'plan', 'country_code' => 'country', 'owner_email' => 'owner email', 'legal_name' => 'legal name'];
    }
}
