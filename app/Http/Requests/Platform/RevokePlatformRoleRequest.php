<?php

namespace App\Http\Requests\Platform;

use App\Domain\Platform\PlatformAbility;
use App\Domain\Platform\PlatformAuthorizer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** One dialog per (administrator, role) in the list, so errors go to a bag named after the pair. */
final class RevokePlatformRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(PlatformAuthorizer::class)->can($this->user(), PlatformAbility::RevokePlatformRole);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }

    public static function bagFor(string $userId, string $roleKey): string
    {
        return 'revoke_'.$userId.'_'.$roleKey;
    }

    protected function failedValidation(Validator $validator): void
    {
        $user = $this->route('user');
        $role = $this->route('role');
        if (is_object($user) && is_string($role)) {
            $this->errorBag = self::bagFor($user->getKey(), $role);
        }

        parent::failedValidation($validator);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Say why: the reason is kept in the audit log.'];
    }
}
