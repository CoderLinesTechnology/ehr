<?php

namespace App\Domain\Clients;

use App\Domain\Shared\LabelledEnum;

/** How the client's care is paid for. Matches the clients_billing_type_check constraint. */
enum BillingType: string
{
    use LabelledEnum;

    case SelfPay = 'self_pay';
    case Insurance = 'insurance';

    public function label(): string
    {
        return match ($this) {
            self::SelfPay => 'Self pay',
            self::Insurance => 'Insurance',
        };
    }
}
