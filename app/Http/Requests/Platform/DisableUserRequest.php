<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

final class DisableUserRequest extends FormRequest
{
    protected $errorBag = 'disable_user';

    public function authorize(): bool
    {
        return $this->user()?->can('platform.admins.manage') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Say why: the reason is kept in the audit log.'];
    }
}
