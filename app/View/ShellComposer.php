<?php

namespace App\View;

use App\Domain\Identity\PermissionResolver;
use App\Domain\Saas\EntitlementService;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Builds $shell for the layouts: navigation filtered by entitlement AND
 * permission, the organization switcher, banners. A module appears in the
 * navigation only once its route exists — no placeholder screens.
 */
final class ShellComposer
{
    /** Staff navigation, in the order of the product's information architecture. */
    private const APP_NAV = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'home', 'route' => 'app.dashboard', 'active' => ['app.dashboard'], 'feature' => null, 'any' => []],
        ['key' => 'calendar', 'label' => 'Calendar', 'icon' => 'calendar', 'route' => 'app.calendar.index', 'active' => ['app.calendar.*', 'app.appointments.*'], 'feature' => 'calendar', 'any' => ['appointments.view', 'appointments.view_all']],
        ['key' => 'clients', 'label' => 'Clients', 'icon' => 'users', 'route' => 'app.clients.index', 'active' => ['app.clients.*'], 'feature' => 'clients', 'any' => ['clients.view', 'clients.view_all']],
        ['key' => 'messages', 'label' => 'Messages', 'icon' => 'message', 'route' => 'app.messages.index', 'active' => ['app.messages.*'], 'feature' => 'messaging', 'any' => []],
        ['key' => 'tasks', 'label' => 'Tasks', 'icon' => 'check-square', 'route' => 'app.tasks.index', 'active' => ['app.tasks.*'], 'feature' => 'tasks', 'any' => []],
        ['key' => 'billing', 'label' => 'Billing', 'icon' => 'credit-card', 'route' => 'app.billing.index', 'active' => ['app.billing.*'], 'feature' => 'billing', 'any' => []],
        ['key' => 'documents', 'label' => 'Documents', 'icon' => 'file', 'route' => 'app.documents.index', 'active' => ['app.documents.*'], 'feature' => 'documents', 'any' => []],
        ['key' => 'telehealth', 'label' => 'Telehealth', 'icon' => 'video', 'route' => 'app.telehealth.index', 'active' => ['app.telehealth.*'], 'feature' => 'telehealth', 'any' => []],
        ['key' => 'programs', 'label' => 'Programs', 'icon' => 'layers', 'route' => 'app.programs.index', 'active' => ['app.programs.*'], 'feature' => 'programs', 'any' => []],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'bar-chart', 'route' => 'app.reports.index', 'active' => ['app.reports.*'], 'feature' => null, 'any' => ['reports.view']],
    ];

    /** Anyone holding one of these sees the Settings entry. */
    private const SETTINGS_PERMISSIONS = [
        'organization.settings.manage', 'locations.manage', 'services.manage', 'team.view', 'team.manage',
        'roles.manage', 'audit.view', 'demo.manage', 'availability.manage_own', 'availability.manage_all',
    ];

    private const PLATFORM_NAV = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'activity', 'route' => 'platform.dashboard', 'active' => ['platform.dashboard'], 'permission' => 'platform.dashboard.view'],
        ['key' => 'organizations', 'label' => 'Organizations', 'icon' => 'building', 'route' => 'platform.organizations.index', 'active' => ['platform.organizations.*'], 'permission' => 'platform.organizations.view'],
        ['key' => 'users', 'label' => 'Users', 'icon' => 'users', 'route' => 'platform.users.index', 'active' => ['platform.users.*'], 'permission' => 'platform.users.view'],
        ['key' => 'plans', 'label' => 'Plans & features', 'icon' => 'layers', 'route' => 'platform.plans.index', 'active' => ['platform.plans.*'], 'permission' => 'platform.plans.manage'],
        ['key' => 'admins', 'label' => 'Administrators', 'icon' => 'shield', 'route' => 'platform.admins.index', 'active' => ['platform.admins.*'], 'permission' => 'platform.admins.manage'],
        ['key' => 'settings', 'label' => 'Platform settings', 'icon' => 'sliders', 'route' => 'platform.settings.edit', 'active' => ['platform.settings.*'], 'permission' => 'platform.settings.manage'],
        ['key' => 'audit', 'label' => 'Audit log', 'icon' => 'history', 'route' => 'platform.audit.index', 'active' => ['platform.audit.*'], 'permission' => 'platform.audit.view'],
    ];

    /** @var array<string, mixed>|null */
    private ?array $shell = null;

    public function __construct(
        private readonly Request $request,
        private readonly TenantContext $tenant,
        private readonly SettingsService $settings,
        private readonly EntitlementService $entitlements,
        private readonly PermissionResolver $permissions,
    ) {}

    public function compose(View $view): void
    {
        $view->with('shell', $this->shell ??= $this->build());
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        /** @var User|null $user */
        $user = $this->request->user();
        $organization = $this->tenant->organization();
        $isPlatformRoute = $this->request->routeIs('platform.*');

        $announcement = $this->settings->platform('platform.announcement');

        return [
            'platformName' => (string) $this->settings->platform('platform.name'),
            'announcement' => $announcement ? ['message' => $announcement, 'level' => $this->settings->platform('platform.announcement_level')] : null,
            'legal' => [
                'termsUrl' => $this->settings->platform('legal.terms_url'),
                'privacyUrl' => $this->settings->platform('legal.privacy_url'),
            ],
            'user' => $user ? ['name' => $user->name, 'email' => $user->email, 'initials' => $user->initials()] : null,
            'organization' => $organization ? [
                'name' => $organization->name,
                'slug' => $organization->slug,
                'logoUrl' => null,
                'isDemoDataPresent' => Client::query()->demo()->exists(),
            ] : null,
            'organizations' => $user ? $this->organizations($user, $organization) : [],
            'nav' => $isPlatformRoute ? $this->platformNav($user) : ($organization ? $this->appNav($organization) : []),
            'secondaryNav' => (! $isPlatformRoute && $organization) ? $this->secondaryNav() : [],
            'searchUrl' => ($organization && Route::has('app.search')) ? route('app.search') : null,
            'accountUrl' => Route::has('account.profile') ? route('account.profile') : null,
            'logoutUrl' => route('logout'),
            'platformUrl' => ($user && ! $isPlatformRoute && $this->permissions->isPlatformUser($user) && Route::has('platform.dashboard'))
                ? route('platform.dashboard') : null,
            'appUrl' => ($user && $isPlatformRoute) ? route('home') : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function appNav(Organization $organization): array
    {
        $items = [];
        foreach (self::APP_NAV as $item) {
            if (! Route::has($item['route'])) {
                continue;
            }
            if ($item['feature'] !== null && ! $this->entitlements->allows($organization, $item['feature'])) {
                continue;
            }
            if ($item['any'] !== [] && ! Gate::any($item['any'])) {
                continue;
            }
            $items[] = $this->item($item);
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function secondaryNav(): array
    {
        if (! Route::has('app.settings.index') || ! Gate::any(self::SETTINGS_PERMISSIONS)) {
            return [];
        }

        return [$this->item(['key' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'route' => 'app.settings.index', 'active' => ['app.settings.*']])];
    }

    /** @return list<array<string, mixed>> */
    private function platformNav(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $items = [];
        foreach (self::PLATFORM_NAV as $item) {
            if (Route::has($item['route']) && $this->permissions->platformHas($user, $item['permission'])) {
                $items[] = $this->item($item);
            }
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function item(array $item): array
    {
        return [
            'key' => $item['key'],
            'label' => $item['label'],
            'icon' => $item['icon'],
            'url' => route($item['route']),
            'active' => $this->request->routeIs(...$item['active']),
            'badge' => null,
        ];
    }

    /** @return list<array{name: string, url: string, current: bool}> */
    private function organizations(User $user, ?Organization $current): array
    {
        return Organization::query()
            ->whereIn('id', $user->activeMemberships()->select('organization_id'))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'slug', 'name'])
            ->map(fn (Organization $o) => [
                'name' => $o->name,
                'url' => route('app.dashboard', ['organization' => $o->slug]),
                'current' => $current?->id === $o->id,
            ])->all();
    }
}
