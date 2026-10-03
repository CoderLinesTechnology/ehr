<?php

namespace App\Http\Requests\Scheduling;

use App\Domain\Scheduling\Modality;
use App\Models\Appointment;
use App\Models\Location;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** The details of an existing appointment: modality, place and the scheduling note. */
final class UpdateAppointmentRequest extends FormRequest
{
    public ?Location $location = null;

    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('appointment'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'modality' => ['required', Rule::enum(Modality::class)],
            'location_id' => ['nullable', 'uuid'],
            'scheduling_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['location_id' => 'location', 'scheduling_notes' => 'note'];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && $this->input('modality') === Modality::InPerson->value && $this->filled('location_id')) {
                $this->location = Location::query()->active()->find($this->input('location_id'));
                if ($this->location === null) {
                    $validator->errors()->add('location_id', 'Choose a location from the list.');
                }
            }
        }];
    }

    public function notes(): ?string
    {
        $note = trim((string) $this->input('scheduling_notes'));

        return $note === '' ? null : $note;
    }
}
