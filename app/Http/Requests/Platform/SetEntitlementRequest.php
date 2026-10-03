<?php

namespace App\Http\Requests\Platform;

use App\Domain\Saas\FeatureRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Sets one override. Each feature row has its own dialog, so errors go to a bag named after the feature. */
final class SetEntitlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('platform.features.manage') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $isLimit = $this->isLimit();

        return [
            'feature_key' => ['required', 'string', Rule::in(FeatureRegistry::keys())],
            'enabled' => $isLimit ? ['nullable'] : ['required', 'boolean'],
            'unlimited' => ['sometimes', 'boolean'],
            'limit_value' => $isLimit
                ? [Rule::requiredIf(fn () => ! $this->boolean('unlimited')), 'nullable', 'integer', 'min:0', 'max:2000000000']
                : ['nullable'],
            'reason' => ['required', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public function featureKey(): string
    {
        return (string) $this->validated('feature_key');
    }

    public function isLimit(): bool
    {
        $key = $this->input('feature_key');

        return is_string($key) && in_array($key, FeatureRegistry::keys(), true) && FeatureRegistry::isLimit($key);
    }

    public function enabledValue(): ?bool
    {
        return $this->isLimit() ? null : $this->boolean('enabled');
    }

    public function limitValue(): ?int
    {
        return $this->isLimit() && ! $this->boolean('unlimited') && $this->filled('limit_value') ? (int) $this->input('limit_value') : null;
    }

    public function unlimited(): bool
    {
        return $this->isLimit() && $this->boolean('unlimited');
    }

    /** End of the chosen day in the administrator's own timezone, as a UTC instant. */
    public function expiresAt(): ?CarbonImmutable
    {
        $date = $this->validated('expires_at');

        return $date === null ? null : CarbonImmutable::parse($date, $this->user()->timezone ?: config('app.timezone'))->endOfDay()->utc();
    }

    public static function bagFor(string $featureKey): string
    {
        return 'entitlement_'.$featureKey;
    }

    protected function failedValidation(Validator $validator): void
    {
        $key = $this->input('feature_key');
        if (is_string($key) && in_array($key, FeatureRegistry::keys(), true)) {
            $this->errorBag = self::bagFor($key);
        }

        parent::failedValidation($validator);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why: the reason is kept next to the override and in the audit log.',
            'limit_value.required' => 'Enter a limit, or choose unlimited.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['limit_value' => 'limit', 'expires_at' => 'expiry date'];
    }
}
