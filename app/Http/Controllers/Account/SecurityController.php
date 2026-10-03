<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Laravel\Fortify\Fortify;

/**
 * Password and two-factor management. Every state change is done by Fortify's own routes; this controller only
 * decides what the page may show. Secrets (QR code, setup key, recovery codes) are only rendered when they are
 * needed, only after a recent password confirmation, and never cached.
 */
class SecurityController extends Controller implements HasMiddleware
{
    /** Session key that keeps the "MFA is required" notice alive across the password-confirmation redirect. */
    public const MFA_NOTICE_KEY = 'account.mfa_required';

    /**
     * Declared here, not on the route, so the order is explicit: the notice is stored before password.confirm
     * can redirect away. (The flash from the platform gate would otherwise age out on the confirmation page.)
     *
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware(static function (Request $request, Closure $next) {
                if ($request->session()->has('mfa_required')) {
                    $request->session()->put(self::MFA_NOTICE_KEY, true);
                }

                return $next($request);
            }),
            new Middleware('password.confirm'),
        ];
    }

    public function show(Request $request): Response
    {
        $user = $request->user();
        $session = $request->session();

        $hasSecret = $user->two_factor_secret !== null;
        $confirmed = $hasSecret && $user->two_factor_confirmed_at !== null;
        $state = $confirmed ? 'enabled' : ($hasSecret ? 'pending' : 'disabled');

        $mfaRequired = $session->has('mfa_required') || (bool) $session->get(self::MFA_NOTICE_KEY, false);
        if ($confirmed) {
            $session->forget(self::MFA_NOTICE_KEY);
            $mfaRequired = false;
        }

        $status = $session->get('status');
        $justChanged = in_array($status, [
            Fortify::TWO_FACTOR_AUTHENTICATION_CONFIRMED,
            Fortify::RECOVERY_CODES_GENERATED,
        ], true);

        // Recovery codes are as sensitive as the password: shown right after they are created, or on request
        // (this page already required a recent password confirmation).
        $recoveryCodes = [];
        $recoveryCount = 0;
        if ($confirmed && $user->two_factor_recovery_codes !== null) {
            $codes = $user->recoveryCodes();
            $recoveryCount = count($codes);
            if ($justChanged || $request->query('codes') === 'show') {
                $recoveryCodes = $codes;
            }
        }

        $qrSvg = null;
        $setupKey = null;
        if ($state === 'pending') {
            $qrSvg = $user->twoFactorQrCodeSvg();
            $setupKey = trim(chunk_split(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), 4, ' '));
        }

        return response()
            ->view('account.security', [
                'user' => $user,
                'state' => $state,
                'mfaRequired' => $mfaRequired,
                'qrSvg' => $qrSvg,
                'setupKey' => $setupKey,
                'recoveryCodes' => $recoveryCodes,
                'recoveryCount' => $recoveryCount,
            ])
            ->header('Cache-Control', 'no-store, private');
    }
}
