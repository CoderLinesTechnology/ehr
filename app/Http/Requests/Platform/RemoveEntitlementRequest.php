<?php

namespace App\Http\Requests\Platform;

use App\Domain\Platform\PlatformAbility;
use App\Domain\Platform\PlatformAuthorizer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class RemoveEntitlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(PlatformAuthorizer::class)->can($this->user(), PlatformAbility::RemoveEntitlementOverride);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }

    public static function bagFor(string $featureKey): string
    {
        return 'entitlement_remove_'.$featureKey;
    }

    protected function failedValidation(Validator $validator): void
    {
        $key = $this->route('feature');
        if (is_string($key)) {
            $this->errorBag = self::bagFor($key);
        }

        parent::failedValidation($validator);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Say why: the reason is kept in the audit log.'];
    }
}
