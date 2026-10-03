<?php

namespace App\Http\Requests\Programs;

use App\Domain\Programs\ProgramColor;
use App\Domain\Programs\ProgramStatus;
use App\Domain\Programs\StaffRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Input shape only; the rules live in the Programs domain actions. Authorization is declared on the routes. */
final class LevelChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'level_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500'],
            'authorized_by' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Say why the level of care is changing.', 'level_id.required' => 'Choose the new level of care.'];
    }
}
