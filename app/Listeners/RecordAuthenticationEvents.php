<?php

namespace App\Listeners;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use App\Http\Middleware\EnforceSessionLifetime;
use App\Http\Middleware\EnsurePlatformAccess;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Security events into the audit trail (ASVS V7). Failed sign-ins record the
 * attempted address for abuse detection; passwords never reach the log.
 */
final class RecordAuthenticationEvents
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'login',
            Logout::class => 'logout',
            Failed::class => 'failed',
            Lockout::class => 'lockout',
            PasswordReset::class => 'passwordReset',
            Verified::class => 'verified',
            ValidTwoFactorAuthenticationCodeProvided::class => 'twoFactorPassed',
            TwoFactorAuthenticationEnabled::class => 'twoFactorEnabled',
            TwoFactorAuthenticationConfirmed::class => 'twoFactorConfirmed',
            PasswordUpdatedViaController::class => 'passwordChanged',
            TwoFactorAuthenticationDisabled::class => 'twoFactorDisabled',
            TwoFactorAuthenticationFailed::class => 'twoFactorFailed',
            RecoveryCodesGenerated::class => 'recoveryCodes',
        ];
    }

    public function login(Login $event): void
    {
        if ($event->user instanceof User) {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            $this->session()?->put(EnforceSessionLifetime::SESSION_KEY, now()->getTimestamp());
            $this->audit->record('auth.login', $event->user, context: AuditContext::System);
        }
    }

    public function logout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record('auth.logout', $event->user, context: AuditContext::System);
        }
    }

    public function failed(Failed $event): void
    {
        $this->audit->record(
            'auth.login_failed',
            $event->user instanceof User ? $event->user : null,
            metadata: ['email' => self::attemptedEmail($event->credentials['email'] ?? null)],
            context: AuditContext::System,
        );
    }

    public function lockout(Lockout $event): void
    {
        $this->audit->record('auth.lockout', metadata: ['email' => self::attemptedEmail($event->request->input('email'))], context: AuditContext::System);
    }

    public function passwordReset(PasswordReset $event): void
    {
        $this->audit->record('auth.password_reset', $event->user instanceof User ? $event->user : null, context: AuditContext::System);
    }

    public function verified(Verified $event): void
    {
        $this->audit->record('auth.email_verified', $event->user instanceof User ? $event->user : null, context: AuditContext::System);
    }

    /** Passed the 2FA challenge (code or recovery code): this session has proven the second factor. */
    public function twoFactorPassed(ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        $this->session()?->put(EnsurePlatformAccess::MFA_SESSION_KEY, now()->getTimestamp());
        $this->audit->record('auth.two_factor_passed', $event->user, context: AuditContext::System);
    }

    /**
     * A new secret was generated. Re-enabling with "force" replaces the secret of
     * an already-confirmed account: require confirmation of the new one again.
     */
    public function twoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $user = $event->user;
        $wasConfirmed = $user instanceof User && $user->two_factor_confirmed_at !== null;
        if ($wasConfirmed) {
            $user->forceFill(['two_factor_confirmed_at' => null])->saveQuietly();
            $this->session()?->forget(EnsurePlatformAccess::MFA_SESSION_KEY);
        }
        $this->audit->record($wasConfirmed ? 'auth.two_factor_secret_replaced' : 'auth.two_factor_setup_started', $user, context: AuditContext::System);
    }

    /** Confirming enrolment proves possession of the authenticator in this session. */
    public function twoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->session()?->put(EnsurePlatformAccess::MFA_SESSION_KEY, now()->getTimestamp());
        $this->audit->record('auth.two_factor_enabled', $event->user, context: AuditContext::System);
    }

    public function twoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->session()?->forget(EnsurePlatformAccess::MFA_SESSION_KEY);
        $this->audit->record('auth.two_factor_disabled', $event->user, context: AuditContext::System);
    }

    public function passwordChanged(PasswordUpdatedViaController $event): void
    {
        $this->audit->record('auth.password_changed', $event->user instanceof User ? $event->user : null, context: AuditContext::System);
    }

    /** Bounded, valid UTF-8, lower-case: the attempted address is attacker input. */
    private static function attemptedEmail(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return mb_substr(mb_strtolower(trim(mb_scrub($value, 'UTF-8'))), 0, 254);
    }

    private function session(): ?\Illuminate\Contracts\Session\Session
    {
        $request = request();

        return $request->hasSession() ? $request->session() : null;
    }

    public function twoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->audit->record('auth.two_factor_failed', $event->user, context: AuditContext::System);
    }

    public function recoveryCodes(RecoveryCodesGenerated $event): void
    {
        $this->audit->record('auth.recovery_codes_generated', $event->user, context: AuditContext::System);
    }
}
