<?php

namespace App\Domain\Clients;

use App\Domain\Shared\LabelledEnum;

/** How a client prefers to be reached. Matches the clients_contact_method_check constraint. */
enum ContactMethod: string
{
    use LabelledEnum;

    case Email = 'email';
    case Phone = 'phone';
    case Sms = 'sms';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Phone => 'Phone call',
            self::Sms => 'Text message (SMS)',
        };
    }
}
