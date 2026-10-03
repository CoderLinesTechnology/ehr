<?php

namespace App\Domain\Clients;

use App\Domain\Audit\AuditLogger;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Remove a contact from a client. The only hard delete in the clients module,
 * and only for contacts (clients are archived, never deleted): the audit
 * entry keeps what the contact was, minus the free-text notes.
 */
final class DeleteClientContact
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(Client $client, ClientContact $contact, ?User $actor = null): void
    {
        DB::transaction(function () use ($client, $contact) {
            /** @var ClientContact $locked */
            $locked = ClientContact::query()->lockForUpdate()->where('client_id', $client->id)->findOrFail($contact->id);

            $this->audit->record(
                'client_contact.deleted',
                $locked,
                before: Arr::only($locked->getAttributes(), ['name', 'relationship', 'phone', 'email', 'is_emergency_contact']),
                metadata: ['client_id' => $client->id],
                summary: 'Contact removed from client '.$client->formattedNumber(),
            );

            $locked->delete();
        });
    }
}
