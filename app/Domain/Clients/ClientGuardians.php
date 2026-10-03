<?php

namespace App\Domain\Clients;

use App\Domain\Shared\DomainException;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Organization;
use App\Models\User;
use App\Support\PhoneNumbers;

/**
 * A minor's parent or guardian contacts. The rule: a client of type minor has at least one client contact
 * whose relationship_type is parent or guardian, with a name and a phone number or e-mail address (someone
 * the practice can actually reach). Enforced when a minor is registered, when a client becomes a minor, and
 * when a contact of a minor is changed or removed.
 *
 * Input rows (the client form's "Parent or guardian" block):
 *   guardians => [key => ['name', 'relationship_type' => parent|guardian, 'phone', 'email', 'is_emergency_contact']]
 * Wholly blank rows are ignored (the form renders a spare one).
 */
final class ClientGuardians
{
    public const MAX_ROWS = 5;

    public function __construct(private readonly SaveClientContact $saveContact) {}

    /**
     * @param  mixed  $rows  the `guardians` input
     * @return list<array{name: string, relationship_type: string, relationship: string, phone: ?string, email: ?string, is_emergency_contact: bool}>
     *
     * @throws DomainException
     */
    public function prepare(mixed $rows, Organization $organization): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $text = static fn (string $k): ?string => is_string($row[$k] ?? null) && trim($row[$k]) !== '' ? trim($row[$k]) : null;
            $name = $text('name');
            $phone = $text('phone');
            $email = $text('email');

            if ($name === null && $phone === null && $email === null) {
                continue;
            }
            if (count($out) >= self::MAX_ROWS) {
                throw new DomainException('Add at most '.self::MAX_ROWS.' parents or guardians here; add more on the Contacts tab later.', 'too_many_guardians', 'guardians');
            }

            $field = "guardians.{$key}.";
            if ($name === null) {
                throw new DomainException('Enter the parent\'s or guardian\'s name.', 'guardian_name_required', $field.'name');
            }
            if (mb_strlen($name) > 150) {
                throw new DomainException('The name is too long: use at most 150 characters.', 'guardian_name_too_long', $field.'name');
            }
            if ($phone === null && $email === null) {
                throw new DomainException('Enter a phone number or an email address for the parent or guardian.', 'guardian_contact_required', $field.'phone');
            }

            $type = $text('relationship_type') ?? RelationshipType::Parent->value;
            if (! in_array($type, RelationshipType::guardianValues(), true)) {
                throw new DomainException('Choose Parent or Guardian.', 'invalid_guardian_type', $field.'relationship_type');
            }

            if ($email !== null) {
                $email = mb_strtolower($email);
                if (mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw new DomainException('Enter a valid email address, like name@example.com.', 'invalid_email', $field.'email');
                }
            }
            if ($phone !== null) {
                $phone = PhoneNumbers::normalize($phone, $organization->country_code)
                    ?? throw new DomainException(ClientAttributes::phoneMessage($organization), 'invalid_phone', $field.'phone');
            }

            $out[] = [
                'name' => $name,
                'relationship_type' => $type,
                'relationship' => RelationshipType::from($type)->label(),
                'phone' => $phone,
                'email' => $email,
                'is_emergency_contact' => filter_var($row['is_emergency_contact'] ?? false, FILTER_VALIDATE_BOOL),
            ];
        }

        return $out;
    }

    /**
     * Write prepared rows as contacts of $client (inside the caller's transaction), through SaveClientContact's
     * rules (limit, normalisation, audit).
     *
     * @param  list<array<string, mixed>>  $guardians  from prepare()
     */
    public function save(Client $client, array $guardians, ?User $actor = null): void
    {
        foreach ($guardians as $guardian) {
            ($this->saveContact)($client, $guardian, null, $actor);
        }
    }

    /** The client has a reachable parent or guardian on file. */
    public static function has(Client $client, ?string $ignoringContactId = null): bool
    {
        return ClientContact::query()
            ->where('client_id', $client->id)
            ->whereIn('relationship_type', RelationshipType::guardianValues())
            ->where(fn ($q) => $q->whereNotNull('phone')->orWhereNotNull('email'))
            ->when($ignoringContactId !== null, fn ($q) => $q->whereKeyNot($ignoringContactId))
            ->exists();
    }

    /** @throws DomainException */
    public static function missing(string $field = 'guardians'): DomainException
    {
        return new DomainException(
            'A minor needs a parent or guardian on file: add one with a name and a phone number or email address.',
            'guardian_required',
            $field,
        );
    }
}
