<?php

namespace App\Domain\Telehealth\Providers;

/** Whether the video vendor is set up on this installation (code configuration, not an organization setting). */
enum VideoServiceStatus: string
{
    case Connected = 'connected';
    case NotConfigured = 'not_configured';
    case Fake = 'fake';

    /** Rooms and passes can be requested (the fake client counts: it answers locally, without network). */
    public function usable(): bool
    {
        return $this !== self::NotConfigured;
    }

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::NotConfigured => 'Not set up',
            self::Fake => 'Simulated (local development)',
        };
    }
}
