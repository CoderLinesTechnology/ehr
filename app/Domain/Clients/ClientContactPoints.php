<?php

namespace App\Domain\Clients;

use App\Domain\Shared\DomainException;
use App\Models\Client;
use App\Models\ClientContactPoint;
use App\Models\Organization;
use App\Support\PhoneNumbers;

/**
 * A client's e-mail addresses and phone numbers (client_contact_points).
 *
 * Input, per kind (ContactPointKind::inputKey()):
 *
 *   emails => [key => ['value' => 'a@b.org', 'label' => 'work'], ...], primary_email => key
 *   phones => [key => ['value' => '024 410 0001', 'label' => 'mobile'], ...], primary_phone => key
 *
 * Blank rows are ignored (the form always renders a spare one). Values are normalised (e-mail lower-cased,
 * phone E.164 for the organization's country), a value may occur once per client and kind, at most
 * MAX_PER_KIND rows, and exactly one row is primary: the chosen one, else the first.
 *
 * The older single-value input (`email` / `phone`) still works: it sets the PRIMARY value of that kind and
 * keeps the others (blank removes the primary; the next one takes its place).
 *
 * The primary value of each kind is mirrored on clients.email / clients.phone (sync()), so the list, search,
 * reminders and every reader of those columns keep working unchanged.
 */
final class ClientContactPoints
{
    public const MAX_PER_KIND = 10;

    /**
     * What the input asks for. A kind the input does not mention is absent from the result (left as it is).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, list<array{value: string, label: string, is_primary: bool}>>|null  $current  effective() of the client being edited
     * @return array<string, list<array{value: string, label: string, is_primary: bool}>> kind => rows, primary first
     *
     * @throws DomainException
     */
    public function fromInput(array $input, Organization $organization, ?array $current = null): array
    {
        $wanted = [];

        foreach (ContactPointKind::cases() as $kind) {
            $listKey = $kind->inputKey();

            if (array_key_exists($listKey, $input)) {
                $wanted[$kind->value] = $this->fromRows($kind, $input[$listKey], $input['primary_'.$kind->value] ?? null, $organization);
            } elseif (array_key_exists($kind->column(), $input)) {
                $wanted[$kind->value] = $this->withPrimary($kind, $input[$kind->column()], $current[$kind->value] ?? [], $organization);
            }
        }

        return $wanted;
    }

    /**
     * The client's points as stored, primary first. A client written before contact points existed (or by a
     * factory) that has none of a kind but a value on clients.email / clients.phone reads as that one primary.
     *
     * @return array<string, list<array{value: string, label: string, is_primary: bool}>>
     */
    public function effective(Client $client): array
    {
        $rows = $client->relationLoaded('contactPoints')
            ? $client->getRelation('contactPoints')
            : ClientContactPoint::query()->where('client_id', $client->id)
                ->orderBy('kind')->orderByDesc('is_primary')->orderBy('sort')->limit(2 * self::MAX_PER_KIND)->get();

        $points = [ContactPointKind::Email->value => [], ContactPointKind::Phone->value => []];
        foreach ($rows as $row) {
            $points[$row->kind->value][] = ['value' => $row->value, 'label' => $row->label->value, 'is_primary' => $row->is_primary];
        }

        foreach (ContactPointKind::cases() as $kind) {
            $mirror = $client->getAttributes()[$kind->column()] ?? null;
            if ($points[$kind->value] === [] && filled($mirror)) {
                $points[$kind->value][] = ['value' => $mirror, 'label' => $kind->defaultLabel()->value, 'is_primary' => true];
            }
        }

        return $points;
    }

    /**
     * Write $wanted for a SAVED client (inside the caller's transaction, the client row locked by the caller)
     * and set the mirrors on the model; the caller saves the client. A kind whose rows equal $current is not
     * touched.
     *
     * @param  array<string, list<array{value: string, label: string, is_primary: bool}>>  $wanted  from fromInput()
     * @param  array<string, list<array{value: string, label: string, is_primary: bool}>>  $current  effective() before the change ([] for a new client)
     * @return list<string> the kinds that changed
     */
    public function sync(Client $client, array $wanted, array $current = []): array
    {
        $changed = [];

        foreach ($wanted as $kind => $rows) {
            if ($this->same($current[$kind] ?? [], $rows)) {
                continue;
            }

            ClientContactPoint::query()->where('client_id', $client->id)->where('kind', $kind)->delete();

            foreach ($rows as $sort => $row) {
                $point = new ClientContactPoint;
                $point->forceFill([
                    'client_id' => $client->id,
                    'kind' => $kind,
                    'value' => $row['value'],
                    'label' => $row['label'],
                    'is_primary' => $row['is_primary'],
                    'sort' => $sort,
                ])->save();
            }

            $changed[] = $kind;
        }

        $this->mirror($client, $wanted);

        return $changed;
    }

    /**
     * Set clients.email / clients.phone from $wanted (the primary of each kind given) on the model only.
     *
     * @param  array<string, list<array{value: string, label: string, is_primary: bool}>>  $wanted
     */
    public function mirror(Client $client, array $wanted): void
    {
        foreach ($wanted as $kind => $rows) {
            $primary = null;
            foreach ($rows as $row) {
                if ($row['is_primary']) {
                    $primary = $row['value'];
                    break;
                }
            }
            $client->forceFill([ContactPointKind::from($kind)->column() => $primary]);
        }
    }

    /**
     * Counts per kind, for the audit trail (never the values).
     *
     * @param  array<string, list<array<string, mixed>>>  $points
     * @return array<string, int>
     */
    public static function counts(array $points): array
    {
        return array_map('count', $points);
    }

    /**
     * @param  list<array{value: string, label: string, is_primary: bool}>  $a
     * @param  list<array{value: string, label: string, is_primary: bool}>  $b
     */
    private function same(array $a, array $b): bool
    {
        return array_map(fn ($r) => [$r['value'], $r['label'], (bool) $r['is_primary']], $a)
            === array_map(fn ($r) => [$r['value'], $r['label'], (bool) $r['is_primary']], $b);
    }

    /**
     * @return list<array{value: string, label: string, is_primary: bool}>
     *
     * @throws DomainException
     */
    private function fromRows(ContactPointKind $kind, mixed $rows, mixed $primaryKey, Organization $organization): array
    {
        $field = $kind->inputKey();
        if ($rows === null || $rows === '') {
            return [];
        }
        if (! is_array($rows)) {
            throw new DomainException('Enter the '.$this->plural($kind).' in the fields provided.', 'invalid_contact_points', $field);
        }

        $out = [];
        $seen = [];
        $primary = null;

        foreach ($rows as $key => $row) {
            $raw = is_array($row) ? ($row['value'] ?? null) : $row;
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }

            if (count($out) >= self::MAX_PER_KIND) {
                throw new DomainException('A client can have at most '.self::MAX_PER_KIND.' '.$this->plural($kind).'.', 'too_many_contact_points', $field);
            }

            $value = $this->normalize($kind, $raw, $organization, "{$field}.{$key}.value");

            if (isset($seen[$value])) {
                throw new DomainException('This '.$this->noun($kind).' is already listed above.', 'duplicate_contact_point', "{$field}.{$key}.value");
            }
            $seen[$value] = true;

            $label = is_array($row) && is_string($row['label'] ?? null) && $row['label'] !== '' ? $row['label'] : $kind->defaultLabel()->value;
            if (! array_key_exists($label, ContactPointLabel::optionsFor($kind))) {
                throw new DomainException('Choose a label from the list.', 'invalid_contact_label', "{$field}.{$key}.label");
            }

            if ($primary === null && $primaryKey !== null && (string) $primaryKey === (string) $key) {
                $primary = count($out);
            }

            $out[] = ['value' => $value, 'label' => $label, 'is_primary' => false];
        }

        return $this->ordered($out, $primary ?? 0);
    }

    /**
     * The single-value input: $value becomes the primary of $kind, the other rows stay.
     *
     * @param  list<array{value: string, label: string, is_primary: bool}>  $current
     * @return list<array{value: string, label: string, is_primary: bool}>
     *
     * @throws DomainException
     */
    private function withPrimary(ContactPointKind $kind, mixed $value, array $current, Organization $organization): array
    {
        $others = array_values(array_filter($current, fn ($row) => ! $row['is_primary']));
        $old = array_values(array_filter($current, fn ($row) => $row['is_primary']))[0] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return $this->ordered($others, 0);
        }

        $value = $this->normalize($kind, $value, $organization, $kind->column());
        $others = array_values(array_filter($others, fn ($row) => $row['value'] !== $value));

        return $this->ordered([
            ['value' => $value, 'label' => $old['label'] ?? $kind->defaultLabel()->value, 'is_primary' => true],
            ...$others,
        ], 0);
    }

    /**
     * @param  list<array{value: string, label: string, is_primary: bool}>  $rows
     * @return list<array{value: string, label: string, is_primary: bool}> the primary first, the rest in their order
     */
    private function ordered(array $rows, int $primary): array
    {
        if ($rows === []) {
            return [];
        }

        $first = $rows[$primary];
        unset($rows[$primary]);

        return [
            ['value' => $first['value'], 'label' => $first['label'], 'is_primary' => true],
            ...array_map(fn ($r) => ['value' => $r['value'], 'label' => $r['label'], 'is_primary' => false], array_values($rows)),
        ];
    }

    /** @throws DomainException */
    private function normalize(ContactPointKind $kind, string $raw, Organization $organization, string $field): string
    {
        $raw = trim($raw);

        if ($kind === ContactPointKind::Email) {
            $email = mb_strtolower($raw);
            if (mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new DomainException('Enter a valid email address, like name@example.com.', 'invalid_email', $field);
            }

            return $email;
        }

        return PhoneNumbers::normalize($raw, $organization->country_code)
            ?? throw new DomainException(ClientAttributes::phoneMessage($organization), 'invalid_phone', $field);
    }

    private function noun(ContactPointKind $kind): string
    {
        return $kind === ContactPointKind::Email ? 'email address' : 'phone number';
    }

    private function plural(ContactPointKind $kind): string
    {
        return $kind === ContactPointKind::Email ? 'email addresses' : 'phone numbers';
    }
}
