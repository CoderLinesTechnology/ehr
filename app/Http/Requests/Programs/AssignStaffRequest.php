<?php

namespace App\Http\Requests\Programs;

use App\Domain\Programs\ProgramColor;
use App\Domain\Programs\ProgramStatus;
use App\Domain\Programs\StaffRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Input shape only; the rules live in the Programs domain actions. Authorization is declared on the routes. */
final class AssignStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'membership_id' => ['required', 'uuid'],
            'role' => ['required', 'string', Rule::in(StaffRole::values())],
        ];
    }

}
