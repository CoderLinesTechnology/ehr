<?php

namespace App\Domain\Telehealth;

use App\Domain\Shared\DomainException;
use App\Models\TelehealthSession;
use App\Models\User;

/**
 * "Join Session": the session's video room must be available (prepared outside any transaction), then the session
 * opens with OpenSession's unchanged rules (join window, transitions; the appointment starts with it). Rejoining a
 * running session is a no-op here — the call page prepares the room and the pass itself.
 */
final class StartCall
{
    public function __construct(
        private readonly PrepareRoom $prepare,
        private readonly OpenSession $open,
    ) {}

    public function __invoke(TelehealthSession $session, ?User $actor = null): TelehealthSession
    {
        if ($session->status !== SessionStatus::InProgress) {
            if ($session->isDemo()) {
                throw new DomainException('This is a demo session: video is not connected for demo data.', 'video_demo');
            }
            if (($this->prepare)($session) === null) {
                throw new DomainException('Video calls are not set up yet. Ask your administrator.', 'video_not_configured');
            }
        }

        return ($this->open)($session, $actor);
    }
}
