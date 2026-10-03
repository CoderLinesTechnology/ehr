<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline response hardening (OWASP ASVS V14). Scripts only from our own
 * origin — no inline script anywhere in the application. Two per-route
 * opt-ins, read from route defaults: `media` (camera/microphone for this
 * origin) and `video_call` (frame Daily and delegate devices to it).
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // The telehealth call page (->defaults('video_call', true)) is the only page that may frame anything: Daily
        // Prebuilt, from Daily's three domains (the two webrtc ones are its fallbacks). No Daily script runs in this
        // page — script-src stays 'self' everywhere.
        $videoCall = (bool) ($request->route()?->defaults['video_call'] ?? false);

        $headers = $response->headers;
        $headers->set('Content-Security-Policy', implode('; ', array_filter([
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            $videoCall ? 'frame-src https://*.daily.co https://*.dailywebrtc.com https://*.dailywebrtc.net' : null,
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ])));
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Camera and microphone stay off everywhere except routes that declare
        // ->defaults('media', true) (the telehealth device check and join page),
        // and then only for this origin.
        //
        // The call page must DELEGATE them (and screen sharing, fullscreen, autoplay)
        // to the Daily frame, whose `allow` attribute cannot exceed this policy. An
        // origin list with a wildcard sub-domain ("https://*.daily.co") is not
        // honoured by every browser, so the allowlist is `*`: safe here because the
        // page's CSP frame-src admits only Daily's domains, and a frame receives a
        // feature only when its own `allow` attribute names it.
        $media = (bool) ($request->route()?->defaults['media'] ?? false);
        $headers->set('Permissions-Policy', match (true) {
            $videoCall => 'camera=*, microphone=*, display-capture=*, fullscreen=*, autoplay=*, geolocation=(), payment=()',
            $media => 'camera=(self), microphone=(self), geolocation=(), payment=()',
            default => 'camera=(), microphone=(), geolocation=(), payment=()',
        });
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Pages behind sign-in can contain health information: never store
        // them in shared or browser caches (also stops back-button disclosure).
        if ($request->user() !== null) {
            $headers->set('Cache-Control', 'no-store, private');
            $headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
