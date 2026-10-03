<?php

namespace App\Domain\Clients;

use App\Domain\Shared\LabelledEnum;

/**
 * Who the record is for. Matches the clients_client_type_check constraint.
 *
 *  - adult: one person (the default);
 *  - minor: one person who needs a parent or guardian contact on file;
 *  - couple: a record of its own (number, status, billing, appointments) linked to its two members, each an
 *    individual client (client_couple_members). Couples are made by CreateCouple only.
 */
enum ClientType: string
{
    use LabelledEnum;

    case Adult = 'adult';
    case Minor = 'minor';
    case Couple = 'couple';

    public function isIndividual(): bool
    {
        return $this !== self::Couple;
    }

    /** @return array<string, string> the types a person can have (not couple) */
    public static function individualOptions(): array
    {
        return [self::Adult->value => self::Adult->label(), self::Minor->value => self::Minor->label()];
    }
}
