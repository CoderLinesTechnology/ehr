<?php

namespace App\Http\Requests\Programs;

use Illuminate\Foundation\Http\FormRequest;

/** Input shape only; the rules live in the Programs domain actions. Authorization is declared on the routes. */
final class AttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'attendance' => ['required', 'array', 'max:300'],
            'attendance.*' => ['string', 'max:10'],
        ];
    }
}
