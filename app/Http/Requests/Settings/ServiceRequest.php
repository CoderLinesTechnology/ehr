<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create and edit form of a service. Only the shape is checked here; the business rules (price format,
 * duration range, one modality at least, unique name) live in SaveService/ServiceData and come back as
 * field errors.
 */
final class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'price' => ['required', 'string', 'max:20'],
            'late_cancellation_fee' => ['nullable', 'string', 'max:20'],
            'no_show_fee' => ['nullable', 'string', 'max:20'],
            'cancellation_notice_hours' => ['nullable', 'integer', 'min:0', 'max:168'],
            'billing_behavior' => ['required', 'in:billable,non_billable'],
            'allows_in_person' => ['boolean'],
            'allows_telehealth' => ['boolean'],
            'is_bookable_online' => ['boolean'],
            'requires_documentation' => ['boolean'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'provider_ids' => ['nullable', 'array', 'max:200'],
            'provider_ids.*' => ['string', 'max:40'],
            'location_ids' => ['nullable', 'array', 'max:100'],
            'location_ids.*' => ['string', 'max:40'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the service a name.',
            'duration_minutes.required' => 'Enter how long the service takes, in minutes.',
            'price.required' => 'Enter a price (0 if the service is free).',
            'color.regex' => 'Choose a colour like #5b8def.',
        ];
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->safe()->all();
    }
}
