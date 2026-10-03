<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Absolute session lifetime. The idle timeout is session.lifetime (minutes of
 * inactivity); this caps a session's total age so a continuously used session
 * still has to re-authenticate (HIPAA automatic logoff / ASVS 3.3).
 * The sign-in time is stamped by RecordAuthenticationEvents on Login.
 */
final class EnforceSessionLifetime
{
    public const SESSION_KEY = 'auth.authenticated_at';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && Auth::guard('web')->check()) {
            $session = $request->session();
            $startedAt = $session->get(self::SESSION_KEY);

            if ($startedAt === null) {
                $session->put(self::SESSION_KEY, now()->getTimestamp());
            } elseif (now()->getTimestamp() - (int) $startedAt > config('session.absolute_lifetime', 720) * 60) {
                Auth::guard('web')->logout();
                $session->invalidate();
                $session->regenerateToken();

                return redirect()->route('login')->with('status', 'Your session has ended. Please sign in again.');
            }
        }

        return $next($request);
    }
}
