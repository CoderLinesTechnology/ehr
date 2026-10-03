<?php

namespace App\Domain\Clients;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;
use App\Support\PhoneNumbers;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Add or change an emergency / other contact of a client. Phone numbers are
 * stored in E.164, e-mail lower-cased; a client holds a bounded number of
 * contacts (the list is rendered whole). Audited; the free-text notes never
 * enter the audit trail.
 */
final class SaveClientContact
{
    public const MAX_PER_CLIENT = 25;

    private const FIELDS = ['name', 'relationship', 'phone', 'email', 'is_emergency_contact', 'notes'];

    private const UNAUDITED_CONTENT = ['notes'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  ClientContact|null  $contact  null creates a new contact
     *
     * @throws DomainException
     */
    public function __invoke(Client $client, array $input, ?ClientContact $contact = null, ?User $actor = null): ClientContact
    {
        if ($contact !== null && $contact->client_id !== $client->id) {
            throw new DomainException('That contact does not belong to this client.', 'contact_mismatch');
        }

        $data = $this->prepare($input);

        return DB::transaction(fn () => $contact === null
            ? $this->create($client, $data)
            : $this->update($client, $contact, $data));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function prepare(array $input): array
    {
        $data = Arr::only($input, self::FIELDS);

        foreach ($data as $key => $value) {
            $data[$key] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }

        if (array_key_exists('name', $data) && $data['name'] === null) {
            throw new DomainException('Enter the contact\'s name.', 'name_required', 'name');
        }

        if (array_key_exists('email', $data) && $data['email'] !== null) {
            $data['email'] = mb_strtolower($data['email']);
            if (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
                throw new DomainException('Enter a valid email address.', 'invalid_email', 'email');
            }
        }

        if (array_key_exists('phone', $data) && $data['phone'] !== null) {
            $organization = $this->tenant->organizationOrFail();
            $data['phone'] = PhoneNumbers::normalize($data['phone'], $organization->country_code)
                ?? throw new DomainException(ClientAttributes::phoneMessage($organization), 'invalid_phone', 'phone');
        }

        if (array_key_exists('is_emergency_contact', $data)) {
            $data['is_emergency_contact'] = filter_var($data['is_emergency_contact'], FILTER_VALIDATE_BOOL);
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function create(Client $client, array $data): ClientContact
    {
        // The client row is the mutex for "at most N contacts": two requests cannot both add the last one.
        Client::query()->lockForUpdate()->findOrFail($client->id);

        $existing = ClientContact::query()->where('client_id', $client->id);

        if ($existing->count() >= self::MAX_PER_CLIENT) {
            throw new DomainException('A client can have at most '.self::MAX_PER_CLIENT.' contacts. Remove one before adding another.', 'too_many_contacts');
        }

        $contact = new ClientContact;
        $contact->fill($data);
        $contact->forceFill(['client_id' => $client->id, 'sort' => ((int) $existing->max('sort')) + 1])->save();
        $contact->refresh();

        $this->audit->record(
            'client_contact.created',
            $contact,
            after: Arr::except($data, self::UNAUDITED_CONTENT),
            metadata: ['client_id' => $client->id],
            summary: 'Contact added to client '.$client->formattedNumber(),
        );

        return $contact;
    }

    /** @param array<string, mixed> $data */
    private function update(Client $client, ClientContact $contact, array $data): ClientContact
    {
        /** @var ClientContact $locked */
        $locked = ClientContact::query()->lockForUpdate()->findOrFail($contact->id);
        $locked->fill($data);

        $changed = array_keys($locked->getDirty());
        if ($changed === []) {
            return $contact;
        }

        $metadata = ['client_id' => $client->id, 'fields' => $changed];
        $summary = 'Contact of client '.$client->formattedNumber().' updated';

        if (array_diff($changed, self::UNAUDITED_CONTENT) === []) {
            $this->audit->record('client_contact.updated', $locked, metadata: $metadata, summary: $summary);
        } else {
            $this->audit->recordChanges('client_contact.updated', $locked, ignore: ['updated_at', ...self::UNAUDITED_CONTENT], metadata: $metadata, summary: $summary);
        }

        $locked->save();

        $contact->setRawAttributes($locked->getAttributes(), true);

        return $contact;
    }
}
