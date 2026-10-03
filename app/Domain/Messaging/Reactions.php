<?php

namespace App\Domain\Messaging;

/** The reactions a participant may leave. Mirrored by the CHECK on message_reactions. */
final class Reactions
{
    public const ALLOWED = ['👍', '❤️', '😊', '🙏', '🎉', '👏'];

    public static function allows(string $emoji): bool
    {
        return in_array($emoji, self::ALLOWED, true);
    }
}
