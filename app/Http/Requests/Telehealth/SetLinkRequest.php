<?php

namespace App\Http\Requests\Telehealth;

use Illuminate\Foundation\Http\FormRequest;

/** The meeting link a clinician pastes for one session; the host allowlist and https rule are applied by the domain action. */
final class SetLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `can:join,session`
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['join_url' => ['bail', 'required', 'string', 'max:2048']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['join_url' => 'meeting link'];
    }
}
