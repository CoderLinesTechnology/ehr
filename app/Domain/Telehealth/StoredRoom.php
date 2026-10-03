<?php

namespace App\Domain\Telehealth;

use App\Domain\Telehealth\Providers\VideoRoom;
use Carbon\CarbonImmutable;

/** A session's room as stored, with the window the vendor was last given. */
final readonly class StoredRoom
{
    public function __construct(
        public VideoRoom $room,
        public ?CarbonImmutable $notBefore,
        public ?CarbonImmutable $expiresAt,
    ) {}
}
