<?php

namespace App\Http\Requests\Clients;

use App\Domain\Clients\ClientAttributes;
use App\Support\PhoneNumbers;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An emergency / other contact of a client. Authorized by the route
 * (`can:update,client`); SaveClientContact enforces the rest.
 */
final class ClientContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $organization = tenant()->organizationOrFail();

        return [
            'name' => ['required', 'string', 'max:150'],
            'relationship' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail) use ($organization): void {
                if (PhoneNumbers::normalize((string) $value, $organization->country_code) === null) {
                    $fail(ClientAttributes::phoneMessage($organization));
                }
            }],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'is_emergency_contact' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter the contact\'s name.',
            'email.email' => 'Enter a valid email address, like name@example.com.',
            '*.max' => 'The :attribute is too long: use at most :max characters.',
        ];
    }
}
