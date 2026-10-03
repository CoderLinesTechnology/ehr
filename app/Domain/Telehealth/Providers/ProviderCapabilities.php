<?php

namespace App\Domain\Telehealth\Providers;

/** What a provider can do for WellNest; the screens offer only what the session's provider supports. */
final readonly class ProviderCapabilities
{
    public function __construct(
        public bool $recording = false,
        public bool $transcript = false,
        public bool $waitingRoom = false,
    ) {}
}
