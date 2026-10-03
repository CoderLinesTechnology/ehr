<?php

namespace App\Domain\Telehealth\Providers;

/**
 * A session's room at the vendor. $url is the room's own link: the client link (a visitor without a pass waits in
 * the lobby until admitted). Stored encrypted on the session; handed only to staff who may join.
 */
final readonly class VideoRoom
{
    public function __construct(
        public string $name,
        public string $url,
    ) {}
}
