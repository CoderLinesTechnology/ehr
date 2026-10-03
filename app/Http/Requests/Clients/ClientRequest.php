<?php

namespace App\Http\Requests\Clients;

use App\Domain\Clients\ClientAttributes;
use App\Domain\Clients\ClientRequirements;
use App\Domain\Clients\ClientSex;
use App\Domain\Clients\ContactMethod;
use App\Models\Client;
use App\Support\PhoneNumbers;
use App\Support\Regions;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The client form's input shape. Authorization is the route's `can:`
 * middleware (it runs before this request is even built); the business rules
 * (limits, who may be assigned, the required fields of this organization) are
 * enforced again by the domain action, so this class is about telling the
 * user, field by field, what to fix.
 */
abstract class ClientRequest extends FormRequest
{
    /** Whether the organization's "required for new clients" settings apply. */
    abstract protected function isNewClient(): bool;

    /** The client being edited (null when creating). */
    protected function client(): ?Client
    {
        $client = $this->route('client');

        return $client instanceof Client ? $client : null;
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $organization = tenant()->organizationOrFail();
        $requirements = app(ClientRequirements::class);
        $current = $this->client();
        $today = CarbonImmutable::now($organization->timezone)->toDateString();

        $needDateOfBirth = $this->isNewClient() && $requirements->dateOfBirthRequired($organization);
        $needContact = $this->isNewClient() && $requirements->contactRequired($organization);

        $phone = function (string $attribute, mixed $value, Closure $fail) use ($organization): void {
            if (PhoneNumbers::normalize((string) $value, $organization->country_code) === null) {
                $fail(ClientAttributes::phoneMessage($organization));
            }
        };

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'preferred_name' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => [$needDateOfBirth ? 'required' : 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today, 'after_or_equal:1900-01-01'],
            'sex' => ['nullable', Rule::in(ClientSex::values())],
            'gender_identity' => ['nullable', 'string', 'max:60'],
            'pronouns' => ['nullable', 'string', 'max:40'],

            'email' => [$needContact ? 'required_without:phone' : 'nullable', 'email:rfc', 'max:254'],
            'phone' => [$needContact ? 'required_without:email' : 'nullable', 'string', 'max:32', $phone],
            'preferred_contact_method' => ['nullable', Rule::in(ContactMethod::values())],

            'address_line1' => ['nullable', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country_code' => ['nullable', Rule::in(array_keys(Regions::countries()))],

            'primary_clinician_membership_id' => ['nullable', 'uuid', $this->assignable('organization_memberships', 'primary_clinician_membership_id', $current?->primary_clinician_membership_id, fn ($q) => $q->where('status', 'active')->where('is_provider', true))],
            'primary_location_id' => ['nullable', 'uuid', $this->assignable('locations', 'primary_location_id', $current?->primary_location_id, fn ($q) => $q->where('is_active', true))],
            'referral_source' => ['nullable', 'string', 'max:120'],
            'administrative_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'first_name.required' => 'Enter the client\'s first name.',
            'last_name.required' => 'Enter the client\'s last name.',
            'date_of_birth.required' => 'Enter the client\'s date of birth.',
            'date_of_birth.date_format' => 'Enter the date of birth as a valid date.',
            'date_of_birth.before_or_equal' => 'The date of birth cannot be in the future.',
            'date_of_birth.after_or_equal' => 'Enter a valid date of birth.',
            'sex.in' => 'Choose one of the listed options.',
            'email.email' => 'Enter a valid email address, like name@example.com.',
            'email.required_without' => 'Enter an email address or a phone number so the client can be reached.',
            'phone.required_without' => 'Enter a phone number or an email address so the client can be reached.',
            'preferred_contact_method.in' => 'Choose one of the listed contact methods.',
            'country_code.in' => 'Choose a country from the list.',
            'primary_clinician_membership_id.uuid' => 'Choose an active clinician from the list.',
            'primary_clinician_membership_id.exists' => 'Choose an active clinician from the list.',
            'primary_location_id.uuid' => 'Choose an active location from the list.',
            'primary_location_id.exists' => 'Choose an active location from the list.',
            '*.max' => 'The :attribute is too long: use at most :max characters.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'first_name' => 'first name',
            'middle_name' => 'middle name',
            'last_name' => 'last name',
            'preferred_name' => 'preferred name',
            'date_of_birth' => 'date of birth',
            'gender_identity' => 'gender identity',
            'preferred_contact_method' => 'preferred contact method',
            'address_line1' => 'address line 1',
            'address_line2' => 'address line 2',
            'postal_code' => 'postal code',
            'country_code' => 'country',
            'primary_clinician_membership_id' => 'primary clinician',
            'primary_location_id' => 'primary location',
            'referral_source' => 'referral source',
            'administrative_notes' => 'administrative notes',
        ];
    }

    /**
     * An id that must exist in THIS organization and satisfy $conditions,
     * unless it is the value the client already holds (editing a client must
     * not fail because their clinician has since left).
     */
    private function assignable(string $table, string $field, ?string $unchanged, Closure $conditions): Exists
    {
        $organizationId = tenant()->id();

        return Rule::exists($table, 'id')->where(function ($query) use ($field, $organizationId, $unchanged, $conditions) {
            $query->where('organization_id', $organizationId);

            if ($unchanged === null || $this->input($field) !== $unchanged) {
                $conditions($query);
            }
        });
    }
}
