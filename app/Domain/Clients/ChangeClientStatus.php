<?php

namespace App\Domain\Clients;

use App\Domain\Audit\AuditLogger;
use App\Domain\Saas\LimitReached;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one way a client's status changes (active ⇄ inactive, either → archived,
 * archived → active). Locks the row, checks the transition, stamps
 * archived_at, and writes the audit entry and the timeline entry in the same
 * transaction, so neither can be missing for a change that happened.
 *
 * Clients are never deleted: archiving hides them from everyday lists and
 * frees their place under the plan's limit, restoring brings them back and
 * checks that limit again (any move INTO active does, for live clients;
 * demo clients never count).
 *
 * Who may archive or restore is the policy's call (clients.archive); this
 * action enforces the rules of the data.
 */
final class ChangeClientStatus
{
    /** Allowed moves. Restoring an archived client is always to active. */
    private const TRANSITIONS = [
        'active' => ['inactive', 'archived'],
        'inactive' => ['active', 'archived'],
        'archived' => ['active'],
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ActiveClientLimit $limit,
        private readonly Timeline $timeline,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws DomainException
     * @throws LimitReached
     */
    public function __invoke(Client $client, ClientStatus $to, ?User $actor = null, ?string $reason = null): Client
    {
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        $organization = $this->tenant->organizationOrFail();

        DB::transaction(function () use ($client, $to, $actor, $reason, $organization) {
            /** @var Client $locked */
            $locked = Client::query()->lockForUpdate()->findOrFail($client->id);
            $from = $locked->status;

            if ($from === $to) {
                return;
            }

            if (! in_array($to->value, self::TRANSITIONS[$from->value], true)) {
                throw new DomainException("A client who is {$from->label()} cannot be changed to {$to->label()}.", 'invalid_transition');
            }

            if ($reason === null && $to !== ClientStatus::Active) {
                throw new DomainException(
                    'Give a reason to '.($to === ClientStatus::Archived ? 'archive' : 'mark inactive').' this client.',
                    'reason_required',
                    'reason',
                );
            }

            if ($to === ClientStatus::Active && ! $locked->isDemo()) {
                $this->limit->assertRoomFor($organization);
            }

            $locked->forceFill([
                'status' => $to,
                'archived_at' => $to === ClientStatus::Archived ? now() : null,
            ])->save();

            $number = $locked->formattedNumber();

            $this->audit->record(
                'client.status_changed',
                $locked,
                before: ['status' => $from->value],
                after: ['status' => $to->value],
                metadata: array_filter(['reason' => $reason]),
                summary: "Client {$number}: {$from->label()} → {$to->label()}",
            );

            $this->timeline->record(
                $locked,
                Timeline::ADMINISTRATIVE,
                'client.status_changed',
                match ($to) {
                    ClientStatus::Archived => 'Client archived',
                    ClientStatus::Inactive => 'Client marked inactive',
                    ClientStatus::Active => $from === ClientStatus::Archived ? 'Client restored' : 'Client marked active',
                },
                subject: $locked,
                actorUserId: $actor?->id,
                metadata: array_filter(['from' => $from->value, 'to' => $to->value, 'reason' => $reason]),
            );

            $client->setRawAttributes($locked->getAttributes(), true);
            $client->unsetRelations();
        });

        return $client;
    }
}
