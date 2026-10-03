<?php

namespace App\Http\Requests\Programs;

use App\Domain\Programs\ProgramColor;
use App\Domain\Programs\ProgramStatus;
use App\Domain\Programs\StaffRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Input shape only; the rules live in the Programs domain actions. Authorization is declared on the routes. */
final class SaveLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'eligibility' => ['nullable', 'string', 'max:1000'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function levelInput(): array
    {
        return $this->safe()->all() + ['is_active' => $this->boolean('is_active')];
    }
}
