<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StartSubscriptionRequest extends FormRequest
{
    protected $errorBag = 'subscription_start';

    public function authorize(): bool
    {
        return $this->user()?->can('platform.subscriptions.manage') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'plan_id' => ['bail', 'required', 'uuid', Rule::exists('plans', 'id')->where('is_active', true)],
            'trial' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['plan_id' => 'plan'];
    }
}
