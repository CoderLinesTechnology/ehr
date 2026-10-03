<?php

namespace App\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Presence for messaging: stamps users.last_seen_at at most once a minute. A plain UPDATE (no model
 * events, no audit); the poll endpoint counts as activity but is itself rate-limited.
 */
final class TouchLastSeen
{
    public const EVERY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $seen = $user->getAttributes()['last_seen_at'] ?? null;

            if ($seen === null || now()->diffInSeconds(CarbonImmutable::parse($seen), true) >= self::EVERY_SECONDS) {
                $now = now()->utc();
                DB::table('users')->where('id', $user->getAuthIdentifier())->update(['last_seen_at' => $now->format('Y-m-d H:i:s.uP')]);
                $user->setRawAttributes([...$user->getAttributes(), 'last_seen_at' => $now->format('Y-m-d H:i:s.uP')], true);
            }
        }

        return $next($request);
    }
}
