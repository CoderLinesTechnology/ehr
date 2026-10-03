<?php

namespace App\Http\Requests\Messages;

use Illuminate\Foundation\Http\FormRequest;

final class AddParticipantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'participants' => ['required', 'array', 'min:1', 'max:60'],
            'participants.*' => ['bail', 'string', 'uuid'],
        ];
    }
}
