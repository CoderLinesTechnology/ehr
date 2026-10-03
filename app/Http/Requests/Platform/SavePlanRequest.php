<?php

namespace App\Http\Requests\Platform;

use App\Domain\Saas\FeatureRegistry;
use App\Models\Feature;
use App\Support\Money;
use App\Support\Regions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create and edit share one form: the key is chosen once, when the plan is created. */
final class SavePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('platform.plans.manage') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $currency = strtoupper((string) $this->input('currency', 'GHS')) ?: 'GHS';

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'string', Money::inputRule($currency)],
            'currency' => ['required', 'string', Rule::in(array_keys(Regions::currencies()))],
            'billing_interval' => ['required', Rule::in(['month', 'year'])],
            'trial_days' => ['required', 'integer', 'between:0,365'],
            'is_public' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['required', 'integer', 'between:0,32767'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['boolean'],
            'limits' => ['sometimes', 'array'],
            'unlimited' => ['sometimes', 'array'],
            'unlimited.*' => ['boolean'],
        ];

        if ($this->route('plan') === null) {
            $rules['key'] = ['required', 'string', 'regex:/^[a-z][a-z0-9_-]{1,39}$/', 'unique:plans,key'];
        }

        foreach ($this->limitKeys() as $key) {
            $rules["limits.{$key}"] = [
                Rule::requiredIf(fn () => ! $this->boolean("unlimited.{$key}")),
                'nullable', 'integer', 'min:0', 'max:2000000000',
            ];
        }

        return $rules;
    }

    /**
     * The plan's own columns, in the shape SavePlan expects (price converted
     * from the decimal the form carries to integer minor units).
     *
     * @return array<string, mixed>
     */
    public function planAttributes(): array
    {
        $currency = strtoupper((string) $this->validated('currency'));

        return array_filter([
            'key' => $this->route('plan') === null ? (string) $this->validated('key') : null,
            'name' => (string) $this->validated('name'),
            'description' => $this->validated('description'),
            'price_minor' => Money::toMinor((string) $this->validated('price'), $currency),
            'currency' => $currency,
            'billing_interval' => (string) $this->validated('billing_interval'),
            'trial_days' => (int) $this->validated('trial_days'),
            'is_public' => $this->boolean('is_public'),
            'is_active' => $this->boolean('is_active'),
            'sort' => (int) $this->validated('sort'),
        ], fn ($value, $key) => $key !== 'key' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    /** @return array<string, bool> boolean feature key => enabled (every module, ticked or not) */
    public function featureMatrix(): array
    {
        $matrix = [];
        foreach (array_keys(FeatureRegistry::booleanOptions()) as $key) {
            $matrix[$key] = $this->boolean("features.{$key}");
        }

        return $matrix;
    }

    /** @return array<string, ?int> limit feature key => number, or NULL for unlimited */
    public function limitMatrix(): array
    {
        $matrix = [];
        foreach ($this->limitKeys() as $key) {
            $matrix[$key] = $this->boolean("unlimited.{$key}") || ! $this->filled("limits.{$key}")
                ? null
                : (int) $this->input("limits.{$key}");
        }

        return $matrix;
    }

    /** @return list<string> */
    private function limitKeys(): array
    {
        return array_values(array_map(
            fn (array $definition) => $definition['key'],
            array_filter(FeatureRegistry::definitions(), fn (array $d) => $d['type'] === Feature::TYPE_LIMIT),
        ));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'key.regex' => 'The key may use lowercase letters, numbers, hyphens and underscores, and must start with a letter.',
            'key.unique' => 'That plan key is already in use.',
            'price.regex' => 'Enter the price as a number, for example 250 or 250.50 (no currency symbol).',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['billing_interval' => 'billing interval', 'trial_days' => 'trial length', 'sort' => 'sort order'];
    }
}
