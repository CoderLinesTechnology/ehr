<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limits for the Fortify endpoints Fortify itself does not throttle:
 * forgot-password, reset-password, registration, password confirmation and
 * password change. Per IP and, where an address or account is involved, per
 * address/account too (ASVS 2.2.1).
 */
final class ThrottleAuthEndpoints
{
    /** path => [per-IP per hour, per-identity per hour] */
    private const LIMITS = [
        'forgot-password' => [20, 5],
        'reset-password' => [20, 10],
        'register' => [10, 3],
        'user/confirm-password' => [30, 10],
        'user/password' => [30, 10],
        'email/verification-notification' => [20, 6],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') && ! $request->isMethod('PUT')) {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        $limits = self::LIMITS[$path] ?? null;
        if ($limits === null) {
            return $next($request);
        }

        [$perIp, $perIdentity] = $limits;
        $email = $request->input('email');
        $identity = $request->user()?->getAuthIdentifier()
            ?? (is_string($email) ? mb_strtolower(trim($email)) : null);

        $keys = ["auth-endpoint:{$path}:ip:".$request->ip() => $perIp];
        if ($identity !== null && $identity !== '') {
            $keys["auth-endpoint:{$path}:id:".hash('sha256', (string) $identity)] = $perIdentity;
        }

        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                abort(429, 'Too many attempts. Please wait a while and try again.');
            }
        }
        foreach (array_keys($keys) as $key) {
            RateLimiter::hit($key, 3600);
        }

        return $next($request);
    }
}
