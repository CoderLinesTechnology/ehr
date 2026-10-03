<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

final class EnableUserRequest extends FormRequest
{
    protected $errorBag = 'enable_user';

    public function authorize(): bool
    {
        return $this->user()?->can('platform.admins.manage') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}
