<?php

namespace App\Http\Requests\Scheduling;

use App\Domain\Clients\ClientStatus;
use App\Domain\Clients\ClientVisibility;
use App\Domain\Scheduling\Modality;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Staff booking. Input is only SHAPE here (the domain decides what is allowed); what this request
 * adds is tenant safety: every id is looked up through the tenant-scoped models, so another
 * organization's client, service, clinician or location simply "does not exist", and clients are
 * looked up through ClientVisibility, so a member cannot book someone they may not see.
 *
 * `source` is not an input: this route always books as "staff".
 */
final class StoreAppointmentRequest extends FormRequest
{
    public ?Client $client = null;

    public ?Service $service = null;

    public ?OrganizationMembership $clinician = null;

    public ?Location $location = null;

    public function authorize(): bool
    {
        return Gate::allows('create', Appointment::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['bail', 'required', 'uuid'],
            'service_id' => ['bail', 'required', 'uuid'],
            'clinician_id' => ['bail', 'required', 'uuid'],
            'modality' => ['required', Rule::enum(Modality::class)],
            'location_id' => ['nullable', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'scheduling_notes' => ['nullable', 'string', 'max:2000'],
            'allow_overlap' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['client_id' => 'client', 'service_id' => 'service', 'clinician_id' => 'clinician', 'location_id' => 'location', 'scheduling_notes' => 'note'];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $membership = tenant()->membership();

            $this->client = ClientVisibility::apply(Client::query(), $membership)
                ->where('status', '!=', ClientStatus::Archived->value)->find($this->input('client_id'));
            $this->service = Service::query()->active()->find($this->input('service_id'));
            $this->clinician = OrganizationMembership::query()->providers()->find($this->input('clinician_id'));
            $this->location = $this->modality() === Modality::InPerson && $this->filled('location_id')
                ? Location::query()->active()->find($this->input('location_id')) : null;

            foreach (['client' => 'client_id', 'service' => 'service_id', 'clinician' => 'clinician_id'] as $property => $field) {
                if ($this->{$property} === null) {
                    $validator->errors()->add($field, 'Choose a '.$property.' from the list.');
                }
            }
            if ($this->modality() === Modality::InPerson && $this->filled('location_id') && $this->location === null) {
                $validator->errors()->add('location_id', 'Choose a location from the list.');
            }
        }];
    }

    public function modality(): Modality
    {
        return Modality::from((string) $this->input('modality'));
    }

    /** The wall-clock date and time, read in the place's timezone (the location's; the organization's for telehealth). */
    public function startsAt(): CarbonImmutable
    {
        $timezone = $this->location?->timezone ?? tenant()->organizationOrFail()->timezone;

        return CarbonImmutable::createFromFormat('!Y-m-d H:i', $this->input('date').' '.$this->input('time'), $timezone)->utc();
    }

    /** Only someone who may double-book can ask to. */
    public function allowOverlap(): bool
    {
        return $this->boolean('allow_overlap') && Gate::allows('overbook', Appointment::class);
    }

    public function notes(): ?string
    {
        $note = trim((string) $this->input('scheduling_notes'));

        return $note === '' ? null : $note;
    }
}
