<?php

use App\Domain\Shared\DomainException;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnforceSessionLifetime;
use App\Http\Middleware\EnsurePlatformAccess;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottleAuthEndpoints;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Each domain keeps its listeners next to its events.
    ->withEvents(discover: [
        __DIR__.'/../app/Listeners',
        __DIR__.'/../app/Domain/*/Listeners',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Outermost, so every response (including ones produced by later
        // middleware: 419, 429, maintenance) carries the security headers.
        $middleware->prepend(SecurityHeaders::class);

        $middleware->web(append: [
            AuthenticateSession::class,   // a password change signs out every other session
            EnsureUserIsActive::class,
            EnforceSessionLifetime::class,
            ThrottleAuthEndpoints::class,
        ]);

        // Links in emails (password reset, invitations) are built from the
        // request host: only the configured application host (and its tenant
        // subdomains) is accepted.
        $middleware->trustHosts(subdomains: true);

        // Behind a TLS-terminating load balancer set TRUSTED_PROXIES (comma
        // separated IPs/CIDRs) so HTTPS, client IPs and HSTS are correct.
        $proxies = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))));
        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies);
        }

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'feature' => EnsureFeature::class,
            'platform' => EnsurePlatformAccess::class,
        ]);

        // The tenant must be known before route-model binding runs, so that
        // {client}/{appointment}/… bindings are confined to the organization.
        // Authentication already sits above SubstituteBindings in the priority list.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A business rule refused the action: show its safe message where the
        // user was, keep their input. Never a stack trace, never a 500.
        $exceptions->render(function (DomainException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->userMessage(), 'code' => $e->errorCode()], 422);
            }

            $errors = [$e->field() ?? 'domain' => $e->userMessage()];

            return back()->withInput($request->except(['password', 'password_confirmation', 'current_password']))
                ->withErrors($errors)
                ->with('error', $e->userMessage());
        });

        $exceptions->dontReport([DomainException::class]);
    })->create();
