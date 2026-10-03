<?php

namespace App\Http\Requests\Scheduling;

use App\Domain\Scheduling\BlockedTimeKind;
use App\Models\Location;
use App\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Blocked time (leave, holiday, a closure). `manage_own` blocks only the member's own calendar;
 * organization-wide blocks (no clinician) and other people's blocks need `manage_all`.
 */
final class BlockedTimeRequest extends FormRequest
{
    public ?OrganizationMembership $membership = null;

    public ?Location $location = null;

    public bool $everyone = false;

    public function authorize(): bool
    {
        return Gate::any(['availability.manage_own', 'availability.manage_all']);
    }

    /** No times means all day: the form has no separate switch to disagree with the fields. */
    protected function prepareForValidation(): void
    {
        $this->merge(['all_day' => ! $this->filled('start_time') && ! $this->filled('end_time')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', Rule::in(['clinician', 'everyone'])],
            'membership_id' => ['nullable', 'uuid'],
            'kind' => ['required', Rule::enum(BlockedTimeKind::class)],
            'title' => ['nullable', 'string', 'max:120'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'required_with:end_time', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_with:start_time', 'date_format:H:i'],
            'location_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['start_date' => 'date', 'end_date' => 'last day', 'start_time' => 'start time', 'end_time' => 'end time', 'location_id' => 'location'];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $all = Gate::allows('availability.manage_all');
            $this->everyone = $this->input('scope') === 'everyone';

            if ($this->everyone && ! $all) {
                $validator->errors()->add('scope', 'Only someone who manages everyone\'s availability can block the whole organization.');

                return;
            }
            if (! $this->everyone) {
                $id = $all && $this->filled('membership_id') ? $this->input('membership_id') : tenant()->membership()->id;
                $this->membership = OrganizationMembership::query()->providers()->find($id);
                if ($this->membership === null) {
                    $validator->errors()->add('membership_id', 'Choose a clinician.');
                }
            }
            if ($this->filled('location_id')) {
                $this->location = Location::query()->find($this->input('location_id'));
                if ($this->location === null) {
                    $validator->errors()->add('location_id', 'Choose a location from the list.');
                }
            }
        }];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} the timed block's instants, read in the place's timezone */
    public function instants(): array
    {
        $timezone = $this->location?->timezone ?? tenant()->organizationOrFail()->timezone;
        $at = fn (string $time) => CarbonImmutable::createFromFormat('!Y-m-d H:i', $this->input('start_date').' '.$time, $timezone)->utc();

        return [$at($this->input('start_time')), $at($this->input('end_time'))];
    }
}
