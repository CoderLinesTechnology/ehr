<?php

namespace App\Http\Requests\Clients;

use App\Domain\Clients\BillingType;
use App\Domain\Clients\ClientAttributes;
use App\Domain\Clients\ClientContactPoints;
use App\Domain\Clients\ClientGuardians;
use App\Domain\Clients\ClientRequirements;
use App\Domain\Clients\ClientSex;
use App\Domain\Clients\ClientType;
use App\Domain\Clients\ContactMethod;
use App\Domain\Clients\ContactPointKind;
use App\Domain\Clients\ContactPointLabel;
use App\Domain\Clients\RelationshipType;
use App\Models\Client;
use App\Support\PhoneNumbers;
use App\Support\Regions;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The client form's input shape. Authorization is the route's `can:`
 * middleware (it runs before this request is even built); the business rules
 * (limits, who may be assigned, the required fields of this organization,
 * a minor's guardian, a couple's members) are enforced again by the domain
 * actions, so this class is about telling the user, field by field, what to fix.
 *
 * Only the sections that apply to the chosen client type are validated: the
 * form posts every section (the others are hidden by script, or simply left
 * blank without it), and the domain ignores what does not apply.
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

    /** The type this submission is for (the edited client's own when the form does not say). */
    public function clientType(): ClientType
    {
        $raw = $this->input('client_type');

        return (is_string($raw) ? ClientType::tryFrom($raw) : null) ?? $this->client()?->client_type ?? ClientType::Adult;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $organization = tenant()->organizationOrFail();
        $requirements = app(ClientRequirements::class);
        $current = $this->client();
        $today = CarbonImmutable::now($organization->timezone)->toDateString();
        $type = $this->clientType();
        $person = $type->isIndividual();

        $needDateOfBirth = $this->isNewClient() && $person && $requirements->dateOfBirthRequired($organization);

        $phone = function (string $attribute, mixed $value, Closure $fail) use ($organization): void {
            if (PhoneNumbers::normalize((string) $value, $organization->country_code) === null) {
                $fail(ClientAttributes::phoneMessage($organization));
            }
        };

        $rules = [
            'client_type' => ['nullable', Rule::in(ClientType::values())],
            // A new couple's name is derived from its members; once it exists it is edited like any name.
            'first_name' => [($person || ! $this->isNewClient()) ? 'required' : 'nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => [($person || ! $this->isNewClient()) ? 'required' : 'nullable', 'string', 'max:100'],
            'preferred_name' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => [$needDateOfBirth ? 'required' : 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today, 'after_or_equal:1900-01-01'],
            'sex' => ['nullable', Rule::in(ClientSex::values())],
            'gender_identity' => ['nullable', 'string', 'max:60'],
            'pronouns' => ['nullable', 'string', 'max:40'],

            // A single e-mail / phone (older callers) or the lists the form sends.
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:32', $phone],
            'emails' => ['nullable', 'array', 'max:'.ClientContactPoints::MAX_PER_KIND],
            'emails.*' => ['array'],
            'emails.*.value' => ['nullable', 'string', 'email:rfc', 'max:254', 'distinct:ignore_case'],
            'emails.*.label' => ['nullable', Rule::in(array_keys(ContactPointLabel::optionsFor(ContactPointKind::Email)))],
            'primary_email' => ['nullable', 'string', 'max:20'],
            'phones' => ['nullable', 'array', 'max:'.ClientContactPoints::MAX_PER_KIND],
            'phones.*' => ['array'],
            'phones.*.value' => ['nullable', 'string', 'max:32', $phone],
            'phones.*.label' => ['nullable', Rule::in(ContactPointLabel::values())],
            'primary_phone' => ['nullable', 'string', 'max:20'],
            'preferred_contact_method' => ['nullable', Rule::in(ContactMethod::values())],

            'address_line1' => ['nullable', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country_code' => ['nullable', Rule::in(array_keys(Regions::countries()))],

            'billing_type' => ['nullable', Rule::in(BillingType::values())],
            'primary_clinician_membership_id' => ['nullable', 'uuid', $this->assignable('organization_memberships', 'primary_clinician_membership_id', $current?->primary_clinician_membership_id, fn ($q) => $q->where('status', 'active')->where('is_provider', true))],
            'primary_location_id' => ['nullable', 'string', Rule::when(
                $this->input('primary_location_id') !== ClientAttributes::VIRTUAL,
                ['uuid', $this->assignable('locations', 'primary_location_id', $current?->primary_location_id, fn ($q) => $q->where('is_active', true))],
            )],
            'referral_source' => ['nullable', 'string', 'max:120'],
            'administrative_notes' => ['nullable', 'string', 'max:5000'],
        ];

        if ($type === ClientType::Minor) {
            $rules += [
                'guardians' => ['nullable', 'array', 'max:'.ClientGuardians::MAX_ROWS],
                'guardians.*' => ['array'],
                'guardians.*.name' => ['nullable', 'string', 'max:150', 'required_with:guardians.*.phone,guardians.*.email'],
                'guardians.*.relationship_type' => ['nullable', Rule::in(RelationshipType::guardianValues())],
                'guardians.*.phone' => ['nullable', 'string', 'max:32', $phone],
                'guardians.*.email' => ['nullable', 'email:rfc', 'max:254'],
                'guardians.*.is_emergency_contact' => ['nullable', 'boolean'],
            ];
        }

        if ($type === ClientType::Couple && $current === null) {
            $rules += [
                'partners' => ['required', 'array', 'size:2'],
                'partners.*' => ['array'],
                'partners.*.mode' => ['required', Rule::in(['existing', 'new'])],
                'partners.*.client_id' => ['nullable', 'required_if:partners.*.mode,existing', 'uuid'],
                'partners.*.first_name' => ['nullable', 'required_if:partners.*.mode,new', 'string', 'max:100'],
                'partners.*.last_name' => ['nullable', 'required_if:partners.*.mode,new', 'string', 'max:100'],
                'partners.*.email' => ['nullable', 'email:rfc', 'max:254'],
                'partners.*.phone' => ['nullable', 'string', 'max:32', $phone],
                'partners.*.date_of_birth' => [
                    ...($requirements->dateOfBirthRequired($organization) ? ['required_if:partners.*.mode,new'] : []),
                    'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today, 'after_or_equal:1900-01-01',
                ],
            ];
        }

        if ($type === ClientType::Couple && $current !== null) {
            $rules += [
                'members' => ['nullable', 'array', 'size:2'],
                'members.*' => ['nullable', 'uuid', 'distinct'],
            ];
        }

        return $rules;
    }

    /**
     * Checks across fields: the organization's "contact required" (any e-mail or phone will do) and, for a new
     * minor, at least one complete parent or guardian.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $organization = tenant()->organizationOrFail();
            $type = $this->clientType();

            if ($this->isNewClient() && $type->isIndividual() && app(ClientRequirements::class)->contactRequired($organization) && ! $this->hasAnyContactPoint()) {
                $validator->errors()->add('email', 'Enter an email address or a phone number so the client can be reached.');
            }

            if ($this->isNewClient() && $type === ClientType::Minor && ! $this->hasCompleteGuardian()) {
                $validator->errors()->add('guardians', ClientGuardians::missing()->userMessage());
            }
        }];
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
            'client_type.in' => 'Choose Adult, Minor or Couple.',
            'billing_type.in' => 'Choose Self pay or Insurance.',
            'email.email' => 'Enter a valid email address, like name@example.com.',
            'emails.*.value.email' => 'Enter a valid email address, like name@example.com.',
            'emails.*.value.distinct' => 'This email address is already listed above.',
            'emails.max' => 'A client can have at most :max email addresses.',
            'phones.max' => 'A client can have at most :max phone numbers.',
            'emails.*.label.in' => 'Choose a label from the list.',
            'phones.*.label.in' => 'Choose a label from the list.',
            'preferred_contact_method.in' => 'Choose one of the listed contact methods.',
            'country_code.in' => 'Choose a country from the list.',
            'primary_clinician_membership_id.uuid' => 'Choose an active clinician from the list.',
            'primary_clinician_membership_id.exists' => 'Choose an active clinician from the list.',
            'primary_location_id.uuid' => 'Choose a location from the list, or Virtual (telehealth).',
            'primary_location_id.exists' => 'Choose a location from the list, or Virtual (telehealth).',
            'guardians.*.name.required_with' => 'Enter the parent\'s or guardian\'s name.',
            'guardians.*.relationship_type.in' => 'Choose Parent or Guardian.',
            'guardians.*.email.email' => 'Enter a valid email address, like name@example.com.',
            'partners.required' => 'A couple has two members: choose or add both partners.',
            'partners.size' => 'A couple has two members: choose or add both partners.',
            'partners.*.mode.required' => 'Choose whether this partner is an existing client or a new one.',
            'partners.*.client_id.required_if' => 'Choose the partner from your client list.',
            'partners.*.client_id.uuid' => 'Choose the partner from your client list.',
            'partners.*.first_name.required_if' => 'Enter the partner\'s first name.',
            'partners.*.last_name.required_if' => 'Enter the partner\'s last name.',
            'partners.*.email.email' => 'Enter a valid email address, like name@example.com.',
            'partners.*.date_of_birth.required_if' => 'Enter the partner\'s date of birth.',
            'partners.*.date_of_birth.date_format' => 'Enter the date of birth as a valid date.',
            'partners.*.date_of_birth.before_or_equal' => 'The date of birth cannot be in the future.',
            'members.size' => 'A couple has two members: choose both.',
            'members.*.uuid' => 'Choose a client from your client list.',
            'members.*.distinct' => 'Choose two different people for the couple.',
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
            'emails.*.value' => 'email address',
            'phones.*.value' => 'phone number',
            'guardians.*.name' => 'name',
            'guardians.*.phone' => 'phone number',
            'guardians.*.email' => 'email address',
            'partners.*.first_name' => 'first name',
            'partners.*.last_name' => 'last name',
            'partners.*.email' => 'email address',
            'partners.*.phone' => 'phone number',
        ];
    }

    private function hasAnyContactPoint(): bool
    {
        foreach (['email', 'phone'] as $single) {
            if (is_string($this->input($single)) && trim($this->input($single)) !== '') {
                return true;
            }
        }
        foreach (['emails', 'phones'] as $list) {
            foreach ((array) $this->input($list, []) as $row) {
                if (is_array($row) && is_string($row['value'] ?? null) && trim($row['value']) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasCompleteGuardian(): bool
    {
        foreach ((array) $this->input('guardians', []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $filled = static fn (string $k): bool => is_string($row[$k] ?? null) && trim($row[$k]) !== '';
            if ($filled('name') && ($filled('phone') || $filled('email'))) {
                return true;
            }
        }

        return false;
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
