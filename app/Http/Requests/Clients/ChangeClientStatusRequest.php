<?php

namespace App\Http\Requests\Clients;

use App\Domain\Clients\ClientStatus;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Archive, restore, mark inactive. Several confirmation dialogs on the
 * profile post here, so errors go into a bag named after the target status
 * (status_archived, ...): only the dialog that was submitted reopens.
 */
final class ChangeClientStatusRequest extends FormRequest
{
    /**
     * Archiving and restoring need `clients.archive`; every other move needs
     * `clients.edit`. Decided here, before the input is validated, so a user
     * who may not do it learns nothing about the form's rules. A denial for a
     * client outside the user's visibility is a 404 (ClientPolicy).
     */
    public function authorize(): bool
    {
        /** @var Client $client */
        $client = $this->route('client');
        $to = ClientStatus::tryFrom((string) $this->input('status'));

        Gate::authorize(($to === ClientStatus::Archived || $client->status === ClientStatus::Archived) ? 'archive' : 'update', $client);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $status = $this->input('status');

        if (is_string($status) && in_array($status, ClientStatus::values(), true)) {
            $this->errorBag = 'status_'.$status;
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(ClientStatus::values())],
            'reason' => [
                Rule::requiredIf(fn () => in_array($this->input('status'), [ClientStatus::Inactive->value, ClientStatus::Archived->value], true)),
                'nullable', 'string', 'max:500',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.required' => 'Choose what to change the client\'s status to.',
            'status.in' => 'Choose what to change the client\'s status to.',
            'reason.required' => 'Give a reason, so the record shows why the status changed.',
            'reason.max' => 'The reason is too long: use at most :max characters.',
        ];
    }
}
