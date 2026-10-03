<?php

namespace App\Http\Requests\Clients;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Mark inactive" for the clients ticked on the list. The route only needs the member to see clients;
 * every client is authorized on its own (ClientPolicy::changeStatus) when the action runs.
 */
final class BulkClientStatusRequest extends FormRequest
{
    public const MAX = 50;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'clients' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'clients.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'clients.required' => 'Tick at least one client first.',
            'clients.min' => 'Tick at least one client first.',
            'clients.max' => 'Choose at most :max clients at a time.',
            'clients.*.uuid' => 'Tick clients from the list.',
            'reason.required' => 'Give a reason, so each record shows why the status changed.',
            'reason.max' => 'The reason is too long: use at most :max characters.',
        ];
    }
}
