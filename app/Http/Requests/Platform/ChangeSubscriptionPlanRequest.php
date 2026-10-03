<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeSubscriptionPlanRequest extends FormRequest
{
    protected $errorBag = 'subscription_plan';

    public function authorize(): bool
    {
        return $this->user()?->can('platform.subscriptions.manage') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'plan_id' => ['bail', 'required', 'uuid', Rule::exists('plans', 'id')->where('is_active', true)],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['plan_id' => 'plan'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Say why the plan is changing: the reason is kept in the subscription history and the audit log.'];
    }
}
