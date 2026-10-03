<?php

namespace App\Http\Middleware;

use App\Domain\Identity\PermissionResolver;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the Super Admin console: the user must hold a platform role, have
 * confirmed two-factor authentication, AND have proven it in this session
 * (passed the 2FA challenge at sign-in, or just confirmed enrolment). The
 * proof is stamped by RecordAuthenticationEvents. Non-platform users get a
 * 404 so the console's existence is not advertised.
 */
final class EnsurePlatformAccess
{
    public const MFA_SESSION_KEY = 'auth.mfa_verified_at';

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user !== null && $this->permissions->isPlatformUser($user), 404);

        if (! $user->hasConfirmedTwoFactor()) {
            return redirect()->route('account.security')->with('mfa_required', true);
        }

        if (! $request->session()->has(self::MFA_SESSION_KEY)) {
            // A session that never passed the 2FA challenge (e.g. it began
            // before enrolment): sign in again, which now requires the code.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('status', 'For the platform console, please sign in again with your two-factor code.');
        }

        // Platform screens never run inside a tenant.
        $this->tenant->clear();

        return $next($request);
    }
}
