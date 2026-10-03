<?php

namespace App\Domain\Telehealth;

use DateTimeInterface;

/** A session can be joined from $earlyMinutes before it starts until it ends (and never once it is closed). */
final class JoinWindow
{
    public static function isOpen(DateTimeInterface $startsAt, DateTimeInterface $endsAt, int $earlyMinutes, DateTimeInterface $now): bool
    {
        return $now->getTimestamp() >= $startsAt->getTimestamp() - $earlyMinutes * 60
            && $now->getTimestamp() <= $endsAt->getTimestamp();
    }

    /** When the window opens, for "opens at …" hints. */
    public static function opensAt(DateTimeInterface $startsAt, int $earlyMinutes): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($startsAt)->modify("-{$earlyMinutes} minutes");
    }
}
