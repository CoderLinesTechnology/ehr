<?php

namespace App\Domain\Clients;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Edit a client's demographics, e-mails and phones, billing type, primary location (or "virtual"), type
 * (adult ⇄ minor) and, for a couple, its members. Status, number and environment are not editable here
 * (ChangeClientStatus owns status; the others never change). A couple stays a couple and a person never
 * becomes one (CreateCouple makes couples). Becoming a minor needs a reachable parent or guardian, either on
 * file already or given in the same input (`guardians`).
 *
 * The audit entry records what changed, field by field, except the free-text administrative notes (that
 * they changed is recorded, not what they say) and e-mail / phone values: for those it records which kinds
 * changed and how many there are now, never the addresses or numbers.
 */
final class UpdateClient
{
    /** Fields whose content is kept out of the audit trail. */
    private const UNAUDITED_CONTENT = ['administrative_notes', 'email', 'phone'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ClientAttributes $attributes,
        private readonly ClientContactPoints $contactPoints,
        private readonly ClientGuardians $guardians,
        private readonly CoupleMembers $couples,
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

        DB::transaction(function () use ($client, $input, $organization, $actor) {
            // Lock a fresh copy: the "before" in the audit entry must be what is really stored.
            /** @var Client $locked */
            $locked = Client::query()->lockForUpdate()->findOrFail($client->id);

            $data = ($this->attributes)($input, $organization, $locked);
            $type = $this->type($locked, $input);
            $currentPoints = $this->contactPoints->effective($locked);
            $points = $this->contactPoints->fromInput($input, $organization, $currentPoints);

            $newGuardians = [];
            if ($type === ClientType::Minor && $locked->client_type !== ClientType::Minor) {
                $newGuardians = $this->guardians->prepare($input['guardians'] ?? null, $organization);
                if ($newGuardians === [] && ! ClientGuardians::has($locked)) {
                    throw ClientGuardians::missing();
                }
            }

            $locked->fill(Arr::except($data, ClientAttributes::DOMAIN_KEYS));
            $locked->forceFill(Arr::only($data, ClientAttributes::DOMAIN_KEYS) + ['client_type' => $type]);

            // A date cast keeps "1990-03-13 00:00:00" in the attribute; the audit trail (and the DATE column) should say "1990-03-13".
            if ($locked->isDirty('date_of_birth')) {
                $locked->setRawAttributes([...$locked->getAttributes(), 'date_of_birth' => $data['date_of_birth']]);
            }

            $pointsChanged = $this->contactPoints->sync($locked, $points, $currentPoints);
            $this->guardians->save($locked, $newGuardians, $actor);
            if ($locked->isCouple() && array_key_exists('members', $input)) {
                // Audited link by link (client.couple_member_added / _removed).
                $this->couples->replace($locked, $input['members'], $actor);
            }

            $changed = array_keys(Arr::except($locked->getDirty(), ['email', 'phone']));
            if ($pointsChanged !== []) {
                $changed[] = 'contact_points';
            }
            if ($changed === []) {
                return;
            }

            $summary = 'Client '.$locked->formattedNumber().' updated';
            $metadata = ['fields' => $changed];
            if ($pointsChanged !== []) {
                $metadata['contact_points_changed'] = $pointsChanged;
                $metadata['contact_points'] = ClientContactPoints::counts($this->contactPoints->effective($locked->setRelations([])));
            }
            if ($newGuardians !== []) {
                $metadata['guardians_added'] = count($newGuardians);
            }

            $auditable = array_diff($changed, [...self::UNAUDITED_CONTENT, 'contact_points']);
            if ($auditable === []) {
                $this->audit->record('client.updated', $locked, metadata: $metadata, summary: $summary);
            } else {
                $before = [];
                $after = [];
                foreach ($auditable as $key) {
                    $before[$key] = $this->auditValue($locked->getRawOriginal($key));
                    $after[$key] = $this->auditValue($locked->getAttributes()[$key] ?? null);
                }
                $this->audit->record('client.updated', $locked, before: $before, after: $after, metadata: $metadata, summary: $summary);
            }

            $locked->save();

            // Hand the caller's instance back in sync with what was stored (the search projection included).
            $locked->refresh();
            $client->setRawAttributes($locked->getAttributes(), true);
            $client->unsetRelations();
        });

        return $client;
    }

    /**
     * The type after this edit: adult ⇄ minor only.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws DomainException
     */
    private function type(Client $client, array $input): ClientType
    {
        $current = $client->client_type;
        $raw = $input['client_type'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return $current;
        }

        $wanted = ClientType::tryFrom($raw) ?? throw new DomainException('Choose Adult or Minor.', 'invalid_client_type', 'client_type');

        if ($wanted === $current) {
            return $current;
        }
        if ($current === ClientType::Couple) {
            throw new DomainException('A couple record stays a couple. To change who is in it, change its members.', 'couple_type_fixed', 'client_type');
        }
        if ($wanted === ClientType::Couple) {
            throw new DomainException('A person cannot become a couple. Register the couple with its two members instead.', 'couple_needs_members', 'client_type');
        }

        return $wanted;
    }

    private function auditValue(mixed $value): mixed
    {
        return is_bool($value) ? $value : (is_string($value) && in_array($value, ['t', 'f'], true) ? $value === 't' : $value);
    }
}
