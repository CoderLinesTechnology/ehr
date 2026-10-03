<?php

namespace App\Http\Requests\Platform;

use App\Domain\Platform\OrganizationStatus;
use App\Domain\Platform\PlatformAbility;
use App\Domain\Platform\PlatformAuthorizer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One lifecycle form per target status sits on the organization page, each in
 * its own confirmation dialog. Errors go to a bag named after the target
 * status, so a missing reason re-opens only the dialog it belongs to.
 */
final class ChangeOrganizationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(PlatformAuthorizer::class)->can($this->user(), PlatformAbility::ChangeOrganizationStatus);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(OrganizationStatus::class)],
            'reason' => [
                Rule::requiredIf(fn () => $this->target()?->isRestrictive() === true),
                'nullable', 'string', 'max:500',
            ],
        ];
    }

    public function target(): ?OrganizationStatus
    {
        $status = $this->input('status');

        return is_string($status) ? OrganizationStatus::tryFrom($status) : null;
    }

    public static function bagFor(OrganizationStatus $status): string
    {
        return 'status_'.$status->value;
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
        return ['reason.required' => 'Say why: the reason is kept in the organization\'s history and the audit log.'];
    }
}
