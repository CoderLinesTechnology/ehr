<?php

namespace App\Domain\Clients;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Client;
use App\Models\ClientCoupleMember;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The links between a couple record and its members (client_couple_members). The rules:
 *
 *  - the couple is a client of type couple; each member is an individual client (adult or minor), never a
 *    couple, not archived, in the same organization and environment (the composite foreign keys hold the
 *    last two whatever the application does);
 *  - a couple has exactly two members, two different people;
 *  - a client may belong to several couples, but only once to the same one (unique index);
 *  - an EXISTING client can only be linked by someone who may see them (ClientVisibility): linking must not
 *    become a way to reach a record otherwise hidden.
 *
 * Every link and unlink is audited (`client.couple_member_added` / `client.couple_member_removed`, subject
 * the couple, the member identified by number only).
 */
final class CoupleMembers
{
    public const SIZE = 2;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * An existing client chosen as a member, checked.
     *
     * @throws DomainException
     */
    public function existing(mixed $id, string $field, ?OrganizationMembership $viewer = null): Client
    {
        $viewer ??= $this->tenant->membership();
        $notFound = new DomainException('Choose a client from your client list.', 'member_not_found', $field);

        if (! is_string($id) || ! Str::isUuid($id) || $viewer === null) {
            throw $notFound;
        }

        $query = Client::query()->whereKey(strtolower($id));
        ClientVisibility::apply($query, $viewer);
        /** @var Client|null $client */
        $client = $query->first();

        if ($client === null) {
            throw $notFound;
        }
        if ($client->isCouple()) {
            throw new DomainException('A couple cannot be a member of another couple. Choose one person.', 'member_is_couple', $field);
        }
        if ($client->status === ClientStatus::Archived) {
            throw new DomainException('This client is archived. Restore them before adding them to a couple.', 'member_archived', $field);
        }
        if ($client->isDemo()) {
            throw new DomainException('Demo clients cannot be linked to real records.', 'member_is_demo', $field);
        }

        return $client;
    }

    /** Link $member to $couple (inside the caller's transaction). */
    public function link(Client $couple, Client $member, ?User $actor = null): void
    {
        $link = new ClientCoupleMember;
        $link->forceFill([
            'record_environment' => $couple->record_environment,
            'couple_client_id' => $couple->id,
            'member_client_id' => $member->id,
        ])->save();

        $this->audit->record(
            'client.couple_member_added',
            $couple,
            metadata: ['member_client_id' => $member->id, 'member_client_number' => $member->client_number],
            summary: 'Client '.$member->formattedNumber().' added to couple '.$couple->formattedNumber(),
        );
    }

    /**
     * Make $couple's members exactly $memberIds (two ids of existing clients). Called with the couple row locked.
     *
     * @return bool whether anything changed
     *
     * @throws DomainException
     */
    public function replace(Client $couple, mixed $memberIds, ?User $actor = null): bool
    {
        if (! $couple->isCouple()) {
            throw new DomainException('Only a couple has members.', 'not_a_couple', 'members');
        }

        $ids = is_array($memberIds) ? array_values(array_filter($memberIds, fn ($v) => is_string($v) && $v !== '')) : [];
        if (count($ids) !== self::SIZE) {
            throw new DomainException('A couple has two members: choose both.', 'couple_needs_two', 'members');
        }

        $current = ClientCoupleMember::query()->where('couple_client_id', $couple->id)->pluck('member_client_id')->all();

        $members = [];
        foreach ($ids as $i => $id) {
            $id = strtolower($id);
            // A member who is already linked stays even if the editor could not link them today.
            $members[] = in_array($id, $current, true)
                ? Client::query()->findOrFail($id)
                : $this->existing($id, "members.{$i}");
        }
        self::assertDistinct($members[0], $members[1], 'members.1');

        $wanted = array_map(fn (Client $c) => $c->id, $members);
        $removed = array_diff($current, $wanted);
        $added = array_filter($members, fn (Client $c) => ! in_array($c->id, $current, true));

        if ($removed === [] && $added === []) {
            return false;
        }

        foreach ($removed as $id) {
            $gone = Client::query()->findOrFail($id);
            ClientCoupleMember::query()->where('couple_client_id', $couple->id)->where('member_client_id', $id)->delete();
            $this->audit->record(
                'client.couple_member_removed',
                $couple,
                metadata: ['member_client_id' => $gone->id, 'member_client_number' => $gone->client_number],
                summary: 'Client '.$gone->formattedNumber().' removed from couple '.$couple->formattedNumber(),
            );
        }
        foreach ($added as $member) {
            $this->link($couple, $member, $actor);
        }

        return true;
    }

    /** @throws DomainException */
    public static function assertDistinct(Client $a, Client $b, string $field): void
    {
        if ($a->id === $b->id) {
            throw new DomainException('Choose two different people for the couple.', 'same_member_twice', $field);
        }
    }

    /**
     * The couple record's name, derived from its members at creation (editable afterwards like any name):
     * same last name → "Emily & Michael" / "Johnson"; different → "Emily Johnson & Michael" / "Lee".
     *
     * @return array{first_name: string, last_name: string}
     */
    public static function name(string $firstA, string $lastA, string $firstB, string $lastB): array
    {
        $firstA = trim($firstA);
        $lastA = trim($lastA);
        $firstB = trim($firstB);
        $lastB = trim($lastB);

        $shared = mb_strtolower($lastA) === mb_strtolower($lastB);
        $first = $shared ? "{$firstA} & {$firstB}" : "{$firstA} {$lastA} & {$firstB}";

        return ['first_name' => mb_substr($first, 0, 100), 'last_name' => mb_substr($shared ? $lastA : $lastB, 0, 100)];
    }
}
