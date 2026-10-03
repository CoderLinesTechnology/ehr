<?php

namespace App\Http\Requests\Programs;

use App\Domain\Programs\ProgramColor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Input shape only; the rules live in the Programs domain actions. Authorization is declared on the routes. */
final class SaveProgramRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['required', 'string', Rule::in(ProgramColor::values())],
            'icon' => ['nullable', 'string', Rule::in(ProgramColor::ICONS)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'place' => ['nullable', 'string', 'max:40'],
            'is_sud_program' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.required' => 'Give the program a name.', 'starts_on.required' => 'Enter the start date.', 'color.in' => 'Choose a colour for the program.'];
    }

    /** @return array<string, mixed> */
    public function programInput(): array
    {
        return $this->safe()->all();
    }
}
