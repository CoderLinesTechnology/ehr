<?php

namespace App\Domain\Clients;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Edit a client's demographics and contact details. Status, number and
 * environment are not editable here (ChangeClientStatus owns status; the
 * others never change). The audit entry records what changed, field by
 * field, except the free-text administrative notes: those are free text
 * that can hold anything, so the trail only says that they changed.
 */
final class UpdateClient
{
    /** Fields whose content is kept out of the audit trail. */
    private const UNAUDITED_CONTENT = ['administrative_notes'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ClientAttributes $attributes,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws DomainException
     */
    public function __invoke(Client $client, array $input, ?User $actor = null): Client
    {
        $organization = $this->tenant->organizationOrFail();

        DB::transaction(function () use ($client, $input, $organization) {
            // Lock a fresh copy: the "before" in the audit entry must be what is really stored.
            /** @var Client $locked */
            $locked = Client::query()->lockForUpdate()->findOrFail($client->id);

            $data = ($this->attributes)($input, $organization, $locked);
            $locked->fill($data);

            // A date cast keeps "1990-03-13 00:00:00" in the attribute; the audit trail (and the DATE column) should say "1990-03-13".
            if ($locked->isDirty('date_of_birth')) {
                $locked->setRawAttributes([...$locked->getAttributes(), 'date_of_birth' => $data['date_of_birth']]);
            }

            $changed = array_keys($locked->getDirty());
            if ($changed === []) {
                return;
            }

            $summary = 'Client '.$locked->formattedNumber().' updated';

            if (array_diff($changed, self::UNAUDITED_CONTENT) === []) {
                $this->audit->record('client.updated', $locked, metadata: ['fields' => $changed], summary: $summary);
            } else {
                $this->audit->recordChanges(
                    'client.updated',
                    $locked,
                    ignore: ['updated_at', ...self::UNAUDITED_CONTENT],
                    metadata: ['fields' => $changed],
                    summary: $summary,
                );
            }

            $locked->save();

            // Hand the caller's instance back in sync with what was stored.
            $client->setRawAttributes($locked->getAttributes(), true);
            $client->unsetRelations();
        });

        return $client;
    }
}
