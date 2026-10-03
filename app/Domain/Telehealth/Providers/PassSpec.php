<?php

namespace App\Domain\Telehealth\Providers;

use Carbon\CarbonImmutable;

/**
 * What a staff member's call pass allows. $userName is the staff member's professional name (never client data);
 * $userId their membership UUID; $owner lets them admit people from the lobby and end the call for everyone;
 * $record lets them start a cloud recording (organization setting + client consent + clinical access);
 * $startVideoOff / $startAudioOff join with the camera / microphone off (the choice made on the join page — they can
 * be switched on and off again with the call's own controls).
 */
final readonly class PassSpec
{
    public function __construct(
        public CarbonImmutable $expiresAt,
        public string $userName,
        public string $userId,
        public bool $owner,
        public bool $record,
        public bool $startVideoOff = false,
        public bool $startAudioOff = false,
    ) {}
}
