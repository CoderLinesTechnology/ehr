<?php

namespace App\Http\Requests\Programs;

use Illuminate\Foundation\Http\FormRequest;

/** Input shape only; the rules live in the Programs domain actions. Authorization is declared on the routes. */
final class ScheduleSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'place' => ['nullable', 'string', 'max:40'],
            'facilitator_membership_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['title.required' => 'Give the session a title.'];
    }
}
