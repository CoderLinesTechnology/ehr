<?php

namespace App\Http\Requests\Platform;

use App\Domain\Platform\PlatformAbility;
use App\Domain\Platform\PlatformAuthorizer;
use Illuminate\Foundation\Http\FormRequest;

final class EnableUserRequest extends FormRequest
{
    protected $errorBag = 'enable_user';

    public function authorize(): bool
    {
        return app(PlatformAuthorizer::class)->can($this->user(), PlatformAbility::EnableUser);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}
