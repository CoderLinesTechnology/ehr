<?php

namespace App\Http\Requests\Scheduling;

use App\Domain\Scheduling\AvailabilityModality;
use App\Models\Location;
use App\Models\OrganizationMembership;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A weekly availability window. `availability.manage_own` may only write the member's OWN
 * availability (the membership is forced, whatever was posted); `availability.manage_all` chooses
 * the clinician. Anything else is refused.
 */
final class AvailabilityRuleRequest extends FormRequest
{
    public ?OrganizationMembership $membership = null;

    public ?Location $location = null;

    public function authorize(): bool
    {
        return Gate::any(['availability.manage_own', 'availability.manage_all']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'membership_id' => ['nullable', 'uuid'],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'modality' => ['required', Rule::enum(AvailabilityModality::class)],
            'location_id' => ['nullable', 'uuid'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d'],
            'repeat_every_weeks' => ['required', 'integer', 'between:1,8'],
            'is_bookable_online' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'service_ids' => ['nullable', 'array', 'max:200'],
            'service_ids.*' => ['uuid'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['start_time' => 'start time', 'end_time' => 'end time', 'effective_from' => 'start date', 'effective_until' => 'end date', 'repeat_every_weeks' => 'repeat', 'location_id' => 'location'];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $own = tenant()->membership();

            $this->membership = Gate::allows('availability.manage_all') && $this->filled('membership_id')
                ? OrganizationMembership::query()->providers()->find($this->input('membership_id'))
                : (Gate::allows('availability.manage_all') ? null : OrganizationMembership::query()->providers()->find($own->id));
            if ($this->membership === null) {
                $validator->errors()->add('membership_id', 'Choose a clinician.');
            }

            if ($this->filled('location_id')) {
                $this->location = Location::query()->find($this->input('location_id'));
                if ($this->location === null) {
                    $validator->errors()->add('location_id', 'Choose a location from the list.');
                }
            }
        }];
    }
}
