<?php

namespace App\Domain\Platform\Users;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Support action: email the account a fresh verification link. Throttled per
 * account (on top of the route's per-administrator throttle) so one mailbox
 * cannot be flooded, whoever asks.
 */
final class ResendVerification
{
    private const MAX_ATTEMPTS = 3;

    private const WINDOW_SECONDS = 600;

    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(User $target): void
    {
        if ($target->hasVerifiedEmail()) {
            throw new DomainException('This email address is already verified.', 'already_verified');
        }
        if ($target->isDisabled()) {
            throw new DomainException('This account is disabled, so no email is sent to it.', 'user_disabled');
        }

        $key = 'platform-verification:'.$target->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new DomainException('A verification email was already sent a few times just now. Try again in a few minutes.', 'throttled');
        }
        RateLimiter::hit($key, self::WINDOW_SECONDS);

        $target->sendEmailVerificationNotification();

        $this->audit->record(
            'user.verification_resent',
            subject: $target,
            summary: "Verification email re-sent to {$target->name}",
            context: AuditContext::Platform,
        );
    }
}
