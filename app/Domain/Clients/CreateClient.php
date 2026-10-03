<?php

namespace App\Domain\Clients;

use App\Domain\Audit\AuditLogger;
use App\Domain\Clients\Events\ClientCreated;
use App\Domain\Platform\OrganizationCounters;
use App\Domain\Saas\LimitReached;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\RecordEnvironment;
use App\Domain\Tenancy\TenantContext;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one way a live client comes into being (demo clients are written only
 * by the demo-data module). In one transaction it enforces the plan's
 * active-client limit, takes the next client number of the organization,
 * writes the record and its audit entry; ClientCreated then fires after
 * commit and the timeline listener writes the first timeline entry.
 *
 * Status, record environment and client number are never taken from input.
 */
final class CreateClient
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly OrganizationCounters $counters,
        private readonly ActiveClientLimit $limit,
        private readonly ClientRequirements $requirements,
        private readonly ClientAttributes $attributes,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  demographics and contact details (mass-assignable keys only are used)
     *
     * @throws DomainException
     * @throws LimitReached
     */
    public function __invoke(array $input, ?User $actor = null): Client
    {
        $organization = $this->tenant->organizationOrFail();

        $data = ($this->attributes)($input, $organization);
        $this->requirements->assertSatisfied($organization, $data);

        $client = DB::transaction(function () use ($organization, $data, $actor) {
            // Locks the organization first, so the count below is the truth.
            $this->limit->assertRoomFor($organization);

            // Atomic upsert: concurrent creators can never receive the same number,
            // and a rollback (limit, constraint) returns the number: no gaps.
            $number = $this->counters->next($organization->id, OrganizationCounters::CLIENT);

            $client = new Client;
            $client->fill($data);
            $client->forceFill([
                'record_environment' => RecordEnvironment::Live,
                'status' => ClientStatus::Active,
                'client_number' => $number,
                'created_by_user_id' => $actor?->id,
            ])->save();
            // A freshly saved model holds only what was written; callers (and strict mode) expect every column.
            $client->refresh();

            // No names, dates of birth or contact details in the trail: the subject identifies the record.
            $this->audit->record(
                'client.created',
                $client,
                after: ['client_number' => $client->client_number, 'status' => ClientStatus::Active->value],
                summary: 'Client '.$client->formattedNumber().' created',
            );

            ClientCreated::dispatch($client, $actor?->id);

            return $client;
        });

        return $client;
    }
}
