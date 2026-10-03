<?php

namespace App\Providers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\PermissionRegistry;
use App\Domain\Identity\PermissionResolver;
use App\Domain\Saas\EntitlementService;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Listeners\RecordAuthenticationEvents;
use App\Models;
use App\Support\Database\Schema\TenantBlueprint;
use App\Support\Formatter;
use App\View\ShellComposer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Request/job-scoped state: reset between requests and queued jobs.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(PermissionResolver::class);
        $this->app->scoped(SettingsService::class);
        $this->app->scoped(EntitlementService::class);
        $this->app->scoped(Formatter::class);
        $this->app->scoped(ShellComposer::class);
        $this->app->scoped(AuditLogger::class, fn ($app) => new AuditLogger(
            $app->make(TenantContext::class),
            $app->bound('request') ? $app->make('request') : null,
        ));
    }

    public function boot(): void
    {
        TenantBlueprint::register();

        Date::use(CarbonImmutable::class);

        // Local design verification only: render the app "as of" the comps'
        // date (APP_FAKE_NOW="2025-04-28 08:30:00"). Never honoured elsewhere.
        if ($this->app->isLocal() && is_string($fakeNow = env('APP_FAKE_NOW')) && $fakeNow !== '') {
            Date::setTestNow(CarbonImmutable::parse($fakeNow, 'UTC'));
        }
        Model::shouldBeStrict(! $this->app->isProduction());

        // Short, stable aliases in polymorphic columns (audit, timeline).
        Relation::enforceMorphMap([
            'user' => Models\User::class,
            'organization' => Models\Organization::class,
            'membership' => Models\OrganizationMembership::class,
            'role' => Models\Role::class,
            'plan' => Models\Plan::class,
            'subscription' => Models\Subscription::class,
            'entitlement' => Models\OrganizationEntitlement::class,
            'location' => Models\Location::class,
            'service' => Models\Service::class,
            'client' => Models\Client::class,
            'client_contact' => Models\ClientContact::class,
            'availability_rule' => Models\AvailabilityRule::class,
            'blocked_time' => Models\BlockedTime::class,
            'appointment' => Models\Appointment::class,
        ]);

        Paginator::defaultView('pagination.default');
        Paginator::defaultSimpleView('pagination.simple-default');

        // Length over composition rules (NIST SP 800-63B). No breach-corpus
        // lookup: that would be an undeclared outbound data flow.
        Password::defaults(fn () => Password::min(12)->letters()->numbers()->max(128));

        View::composer([
            'components.layouts.app',
            'components.layouts.platform',
            'components.layouts.auth',
            'components.layouts.minimal',
        ], ShellComposer::class);

        Event::subscribe(RecordAuthenticationEvents::class);

        $this->registerAuthorization();
    }

    /**
     * Permission keys are answered here, definitively: platform.* from the
     * user's platform roles, everything else from the current tenant
     * membership. Model policies (record-level rules) run for other abilities.
     */
    private function registerAuthorization(): void
    {
        Gate::before(function (Models\User $user, string $ability) {
            if (! PermissionRegistry::exists($ability)) {
                return null;
            }

            if ($user->isDisabled()) {
                return false;
            }

            $resolver = app(PermissionResolver::class);

            if (PermissionRegistry::isPlatform($ability)) {
                return $resolver->platformHas($user, $ability);
            }

            $membership = app(TenantContext::class)->membership();
            if ($membership === null || $membership->user_id !== $user->id || ! $membership->isActive()) {
                return false;
            }

            return $resolver->membershipHas($membership, $ability);
        });
    }
}
