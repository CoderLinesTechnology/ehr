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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The one way a live individual client (adult or minor) comes into being; couples are made by CreateCouple,
 * which registers its record through register() below (demo clients are written only by the demo-data
 * module). In one transaction it enforces the plan's active-client limit, takes the next client number of
 * the organization, writes the record, its e-mails and phones (ClientContactPoints), a minor's parent or
 * guardian contacts (ClientGuardians) and the audit entry; ClientCreated then fires after commit and the
 * timeline listener writes the first timeline entry.
 *
 * Status, record environment, client number and type are never taken from input as columns.
 */
final class CreateClient
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly OrganizationCounters $counters,
        private readonly ActiveClientLimit $limit,
        private readonly ClientRequirements $requirements,
        private readonly ClientAttributes $attributes,
        private readonly ClientContactPoints $contactPoints,
        private readonly ClientGuardians $guardians,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  demographics, `emails`/`phones` (or a single `email`/`phone`),
     *                                       `client_type` adult|minor, `billing_type`, `primary_location_id`
     *                                       (a location id or "virtual") and, for a minor, `guardians`
     *
     * @throws DomainException
     * @throws LimitReached
     */
    public function __invoke(array $input, ?User $actor = null): Client
    {
        $organization = $this->tenant->organizationOrFail();

        $type = ClientType::tryFrom(is_string($input['client_type'] ?? null) && $input['client_type'] !== '' ? $input['client_type'] : ClientType::Adult->value)
            ?? throw new DomainException('Choose Adult, Minor or Couple.', 'invalid_client_type', 'client_type');
        if (! $type->isIndividual()) {
            throw new DomainException('A couple is registered with its two members.', 'couple_needs_members', 'client_type');
        }

        $data = ($this->attributes)($input, $organization);
        $points = $this->contactPoints->fromInput($input, $organization);
        $guardians = $type === ClientType::Minor ? $this->guardians->prepare($input['guardians'] ?? null, $organization) : [];

        $this->requirements->assertSatisfied($organization, $data + self::primaries($points));
        if ($type === ClientType::Minor && $guardians === []) {
            throw ClientGuardians::missing();
        }

        return DB::transaction(function () use ($data, $type, $points, $guardians, $actor) {
            $client = $this->register($data, $type, $points, $actor, ['guardians' => count($guardians)], function (Client $client) use ($guardians, $actor) {
                $this->guardians->save($client, $guardians, $actor);
            });

            return $client;
        });
    }

    /**
     * Write one new live client record: the limit, the number, the row, its contact points and the audit entry.
     * Inside the caller's transaction (or its own). $beforeAudit runs once the row exists (child rows).
     *
     * @param  array<string, mixed>  $data  from ClientAttributes
     * @param  array<string, list<array{value: string, label: string, is_primary: bool}>>  $points  from ClientContactPoints::fromInput()
     * @param  array<string, mixed>  $auditMetadata
     *
     * @throws LimitReached
     */
    public function register(array $data, ClientType $type, array $points, ?User $actor, array $auditMetadata = [], ?\Closure $beforeAudit = null): Client
    {
        $organization = $this->tenant->organizationOrFail();

        return DB::transaction(function () use ($organization, $data, $type, $points, $actor, $auditMetadata, $beforeAudit) {
            // Locks the organization first, so the count below is the truth.
            $this->limit->assertRoomFor($organization);

            // Atomic upsert: concurrent creators can never receive the same number,
            // and a rollback (limit, constraint) returns the number: no gaps.
            $number = $this->counters->next($organization->id, OrganizationCounters::CLIENT);

            $client = new Client;
            $client->fill(Arr::except($data, ClientAttributes::DOMAIN_KEYS));
            $client->forceFill([
                'record_environment' => RecordEnvironment::Live,
                'status' => ClientStatus::Active,
                'client_number' => $number,
                'client_type' => $type,
                'billing_type' => $data['billing_type'] ?? BillingType::SelfPay->value,
                'is_virtual' => (bool) ($data['is_virtual'] ?? false),
                'created_by_user_id' => $actor?->id,
            ]);
            $this->contactPoints->mirror($client, $points);
            $client->save();

            $this->contactPoints->sync($client, $points);
            if ($beforeAudit !== null) {
                $beforeAudit($client);
            }

            // A freshly saved model holds only what was written; callers (and strict mode) expect every column.
            $client->refresh();

            // No names, dates of birth or contact details in the trail: the subject identifies the record;
            // the metadata holds the kind of record and how many e-mails/phones it has, never their values.
            $this->audit->record(
                'client.created',
                $client,
                after: ['client_number' => $client->client_number, 'status' => ClientStatus::Active->value],
                metadata: [
                    'client_type' => $type->value,
                    'billing_type' => $client->billing_type->value,
                    'is_virtual' => $client->is_virtual,
                    'contact_points' => ClientContactPoints::counts($points + ['email' => [], 'phone' => []]),
                ] + $auditMetadata,
                summary: 'Client '.$client->formattedNumber().' created',
            );

            ClientCreated::dispatch($client, $actor?->id);

            return $client;
        });
    }

    /**
     * The primary e-mail and phone the points describe, as `email` / `phone` (what ClientRequirements reads).
     *
     * @param  array<string, list<array{value: string, label: string, is_primary: bool}>>  $points
     * @return array{email: ?string, phone: ?string}
     */
    public static function primaries(array $points): array
    {
        $primary = static function (array $rows): ?string {
            foreach ($rows as $row) {
                if ($row['is_primary']) {
                    return $row['value'];
                }
            }

            return null;
        };

        return ['email' => $primary($points['email'] ?? []), 'phone' => $primary($points['phone'] ?? [])];
    }
}
