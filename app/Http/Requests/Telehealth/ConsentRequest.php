<?php

namespace App\Http\Requests\Telehealth;

use Illuminate\Foundation\Http\FormRequest;

final class ConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `can:clinical,session`
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['consent' => ['required', 'boolean']];
    }
}
