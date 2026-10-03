<?php

namespace App\Domain\Resources;

/** Who a resource is for. Staff see `staff` and `everyone`; clients will see `clients` and `everyone` in the portal. */
enum ResourceAudience: string
{
    case Staff = 'staff';
    case Clients = 'clients';
    case Everyone = 'everyone';

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff only',
            self::Clients => 'Clients only',
            self::Everyone => 'Staff and clients',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $a) => $a->value, self::cases());
    }
}
