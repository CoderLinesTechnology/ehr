<?php

namespace App\Http\Requests\Scheduling;

use App\Models\Appointment;
use App\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Moving an appointment: a new date and time, optionally another clinician, and a reason. */
final class RescheduleAppointmentRequest extends FormRequest
{
    public ?OrganizationMembership $clinician = null;

    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('appointment'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'clinician_id' => ['nullable', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
            'allow_overlap' => ['nullable', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && $this->filled('clinician_id')) {
                $this->clinician = OrganizationMembership::query()->providers()->find($this->input('clinician_id'));
                if ($this->clinician === null) {
                    $validator->errors()->add('clinician_id', 'Choose a clinician from the list.');
                }
            }
        }];
    }

    /** Read in the appointment's own timezone: the place it happens in. */
    public function startsAt(Appointment $appointment): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d H:i', $this->input('date').' '.$this->input('time'), $appointment->timezone)->utc();
    }

    public function allowOverlap(): bool
    {
        return $this->boolean('allow_overlap') && Gate::allows('overbook', Appointment::class);
    }
}
