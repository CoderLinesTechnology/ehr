<?php

namespace App\Domain\Identity;

use Illuminate\Support\Str;

/** Invitation tokens: 64 random characters in the link, only a SHA-256 hash in the database. */
final class InvitationTokens
{
    public const VALID_DAYS = 7;

    public static function generate(): string
    {
        return Str::random(64);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
