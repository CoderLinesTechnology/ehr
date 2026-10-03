<?php

namespace App\Domain\Telehealth\Providers;

/** The outcome of createMeeting: the join link (null while none has been supplied). */
final readonly class MeetingDetails
{
    public function __construct(public ?string $joinUrl) {}
}
