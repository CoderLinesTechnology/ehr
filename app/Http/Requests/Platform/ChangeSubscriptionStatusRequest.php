<?php

namespace App\Http\Requests\Platform;

use App\Domain\Platform\PlatformAbility;
use App\Domain\Platform\PlatformAuthorizer;
use App\Domain\Saas\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** One form per target status, each with its own error bag (see ChangeOrganizationStatusRequest). */
final class ChangeSubscriptionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(PlatformAuthorizer::class)->can($this->user(), PlatformAbility::ChangeSubscriptionStatus);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // A subscription is never moved back into a trial; every other status is reachable.
            'status' => ['required', Rule::enum(SubscriptionStatus::class)->except([SubscriptionStatus::Trialing])],
            'reason' => ['required', 'string', 'max:500'],
            'grace_ends_at' => ['nullable', 'date', 'after:today'],
        ];
    }

    public function target(): ?SubscriptionStatus
    {
        $status = $this->input('status');

        return is_string($status) ? SubscriptionStatus::tryFrom($status) : null;
    }

    public static function bagFor(SubscriptionStatus $status): string
    {
        return 'subscription_status_'.$status->value;
    }

    /** End of the chosen day in the administrator's own timezone, as a UTC instant (when the grace period lapses). */
    public function graceEndsAt(): ?CarbonImmutable
    {
        $date = $this->validated('grace_ends_at');
        if ($date === null) {
            return null;
        }

        return CarbonImmutable::parse($date, $this->user()->timezone ?: config('app.timezone'))->endOfDay()->utc();
    }

    protected function failedValidation(Validator $validator): void
    {
        $target = $this->target();
        if ($target !== null) {
            $this->errorBag = self::bagFor($target);
        }

        parent::failedValidation($validator);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Say why: the reason is kept in the subscription history and the audit log.'];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['grace_ends_at' => 'grace period end'];
    }
}
