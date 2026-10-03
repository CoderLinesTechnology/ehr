<?php

namespace App\Http\Requests\Programs;

use Illuminate\Foundation\Http\FormRequest;

/** Input shape only; the rules live in TransferClient. Authorization is declared on the routes. */
final class TransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'program_id' => ['required', 'uuid'],
            'level_id' => ['nullable', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['program_id.required' => 'Choose the program to transfer to.', 'program_id.uuid' => 'Choose the program to transfer to.'];
    }
}
