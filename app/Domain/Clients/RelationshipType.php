<?php

namespace App\Domain\Clients;

use App\Domain\Shared\LabelledEnum;

/**
 * The kind of a client contact (client_contacts.relationship_type, CHECK-constrained). The free-text
 * `relationship` stays as the label people see ("Mother", "Aunt Ama"); this is what rules read.
 */
enum RelationshipType: string
{
    use LabelledEnum;

    case Parent = 'parent';
    case Guardian = 'guardian';
    case Partner = 'partner';
    case Spouse = 'spouse';
    case Sibling = 'sibling';
    case Child = 'child';
    case Friend = 'friend';
    case Other = 'other';

    /** Counts as the parent/guardian a minor must have on file. */
    public function isGuardian(): bool
    {
        return $this === self::Parent || $this === self::Guardian;
    }

    public function isPartner(): bool
    {
        return $this === self::Partner || $this === self::Spouse;
    }

    /** @return list<string> */
    public static function guardianValues(): array
    {
        return [self::Parent->value, self::Guardian->value];
    }

    /** @return list<string> */
    public static function partnerValues(): array
    {
        return [self::Partner->value, self::Spouse->value];
    }

    /** @return array<string, string> the two a guardian block offers */
    public static function guardianOptions(): array
    {
        return [self::Parent->value => self::Parent->label(), self::Guardian->value => self::Guardian->label()];
    }
}
