<?php

namespace App\View;

use App\Domain\Identity\MembershipStatus;
use App\Domain\Identity\PermissionResolver;
use App\Domain\Saas\EntitlementService;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Middleware\ResolveTenant;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Builds $shell for the layouts: navigation filtered by entitlement AND
 * permission, the organization switcher, banners. A module appears in the
 * navigation only once its route exists — no placeholder screens.
 */
final class ShellComposer
{
    /**
     * Staff navigation in SPEC decision 4 order (docs/design/SPEC.md): Dashboard, Clients, Appointments, Messages, Tasks,
     * Documents, Resources, Telehealth, Programs, Reports; Settings is appended as the last item (see secondaryNav()).
     * Icons are Lucide names (resources/icons/lucide). The calendar module is labelled "Appointments".
     */
    private const APP_NAV = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'house', 'route' => 'app.dashboard', 'active' => ['app.dashboard'], 'feature' => null, 'any' => []],
        ['key' => 'clients', 'label' => 'Clients', 'icon' => 'users', 'route' => 'app.clients.index', 'active' => ['app.clients.*'], 'feature' => 'clients', 'any' => ['clients.view', 'clients.view_all']],
        ['key' => 'calendar', 'label' => 'Appointments', 'icon' => 'calendar', 'route' => 'app.calendar.index', 'active' => ['app.calendar.*', 'app.appointments.*'], 'feature' => 'calendar', 'any' => ['appointments.view', 'appointments.view_all']],
        ['key' => 'messages', 'label' => 'Messages', 'icon' => 'message-circle', 'route' => 'app.messages.index', 'active' => ['app.messages.*'], 'feature' => 'messaging', 'any' => ['messages.send', 'messages.client']],
        ['key' => 'tasks', 'label' => 'Tasks', 'icon' => 'calendar-check', 'route' => 'app.tasks.index', 'active' => ['app.tasks.*'], 'feature' => 'tasks', 'any' => []],
        ['key' => 'documents', 'label' => 'Documents', 'icon' => 'file-text', 'route' => 'app.documents.index', 'active' => ['app.documents.*'], 'feature' => 'documents', 'any' => []],
        ['key' => 'resources', 'label' => 'Resources', 'icon' => 'book-open', 'route' => 'app.resources.index', 'active' => ['app.resources.*'], 'feature' => null, 'any' => ['resources.view']],
        ['key' => 'telehealth', 'label' => 'Telehealth', 'icon' => 'video', 'route' => 'app.telehealth.index', 'active' => ['app.telehealth.*'], 'feature' => 'telehealth', 'any' => ['telehealth.join', 'telehealth.notes', 'telehealth.manage']],
        ['key' => 'programs', 'label' => 'Programs', 'icon' => 'users-round', 'route' => 'app.programs.index', 'active' => ['app.programs.*'], 'feature' => 'programs', 'any' => ['programs.view']],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'chart-column', 'route' => 'app.reports.index', 'active' => ['app.reports.*'], 'feature' => null, 'any' => ['reports.view']],
    ];

    /** Anyone holding one of these sees the Settings entry. */
    private const SETTINGS_PERMISSIONS = [
        'organization.settings.manage', 'locations.manage', 'services.manage', 'team.view', 'team.manage',
        'roles.manage', 'audit.view', 'demo.manage', 'availability.manage_own', 'availability.manage_all', 'telehealth.manage',
    ];

    private const PLATFORM_NAV = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'route' => 'platform.dashboard', 'active' => ['platform.dashboard'], 'permission' => 'platform.dashboard.view'],
        ['key' => 'organizations', 'label' => 'Organizations', 'icon' => 'building-2', 'route' => 'platform.organizations.index', 'active' => ['platform.organizations.*'], 'permission' => 'platform.organizations.view'],
        ['key' => 'users', 'label' => 'Users', 'icon' => 'users', 'route' => 'platform.users.index', 'active' => ['platform.users.*'], 'permission' => 'platform.users.view'],
        ['key' => 'plans', 'label' => 'Plans & features', 'icon' => 'layers', 'route' => 'platform.plans.index', 'active' => ['platform.plans.*'], 'permission' => 'platform.plans.manage'],
        ['key' => 'admins', 'label' => 'Administrators', 'icon' => 'shield-check', 'route' => 'platform.admins.index', 'active' => ['platform.admins.*'], 'permission' => 'platform.admins.manage'],
        ['key' => 'settings', 'label' => 'Platform settings', 'icon' => 'sliders-horizontal', 'route' => 'platform.settings.edit', 'active' => ['platform.settings.*'], 'permission' => 'platform.settings.manage'],
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

        // The account pages sit outside any organization. They show the navigation of the organization the user last
        // worked in (or their only one) — built with THEIR membership there, exactly as on that organization's pages —
        // so the sidebar never goes empty. Nothing of the organization's data is read beyond what the shell shows.
        if ($user !== null && $this->tenant->organization() === null && $this->request->routeIs('account.*')) {
            $context = $this->accountPagesOrganization($user);
            if ($context !== null) {
                [$organization, $membership] = $context;
                URL::defaults(['organization' => $organization->slug]);

                return $this->tenant->runAs($organization, fn () => $this->shellFor($user), $membership);
            }
        }

        return $this->shellFor($user);
    }

    /**
     * The organization whose navigation the account pages show: the one last worked in, else the user's only one —
     * only while their membership there is active and the organization is open to them.
     *
     * @return array{0: Organization, 1: OrganizationMembership}|null
     */
    private function accountPagesOrganization(User $user): ?array
    {
        $last = $this->request->hasSession() ? $this->request->session()->get(ResolveTenant::LAST_ORGANIZATION_KEY) : null;

        // The user's own memberships (identity, as ResolveTenant reads them): no tenant is set on these pages.
        $membership = $this->tenant->bypass(function () use ($user, $last) {
            $active = OrganizationMembership::query()->where('user_id', $user->id)->where('status', MembershipStatus::Active->value);
            if (is_string($last) && ($found = (clone $active)->where('organization_id', $last)->first()) !== null) {
                return $found;
            }
            $only = (clone $active)->limit(2)->get();

            return $only->count() === 1 ? $only->first() : null;
        });
        if ($membership === null || ! $membership->isActive()) {
            return null;
        }

        $organization = Organization::query()->find($membership->organization_id);

        return $organization !== null && $organization->allowsAccess() ? [$organization, $membership] : null;
    }

    /** @return array<string, mixed> */
    private function shellFor(?User $user): array
    {
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
                'logoUrl' => \App\Http\Controllers\App\Settings\OrganizationController::logoUrl($organization),
                'isDemoDataPresent' => Client::query()->demo()->exists(),
            ] : null,
            'organizations' => $user ? $this->organizations($user, $organization) : [],
            'nav' => $isPlatformRoute ? $this->platformNav($user) : ($organization ? $this->appNav($organization) : $this->homeNav($user)),
            'secondaryNav' => (! $isPlatformRoute && $organization) ? $this->secondaryNav() : [],
            'searchUrl' => ($organization && Route::has('app.search')) ? route('app.search') : null,
            'accountUrl' => Route::has('account.profile') ? route('account.profile') : null,
            'logoutUrl' => route('logout'),
            'roleLabel' => $this->roleLabel($user, $organization, $isPlatformRoute),
            // No notifications module yet: the bell shows its red dot only when this is > 0. Messages badge likewise (item 'badge').
            'unreadNotifications' => 0,
            'notifications' => [],
            'platformUrl' => ($user && ! $isPlatformRoute && $this->permissions->isPlatformUser($user) && Route::has('platform.dashboard'))
                ? route('platform.dashboard') : null,
            'appUrl' => ($user && $isPlatformRoute) ? route('home') : null,
        ];
    }

    /** Second line of the top-bar identity: the member's first role (alphabetical), else the organization name. */
    private function roleLabel(?User $user, ?Organization $organization, bool $isPlatformRoute): string
    {
        if ($isPlatformRoute) {
            return 'Super Admin';
        }

        $membership = $this->tenant->membership();
        if ($membership !== null) {
            $role = $membership->roles()->orderBy('roles.name')->limit(1)->pluck('roles.name')->first();
            if (is_string($role) && $role !== '') {
                return $role;
            }
        }

        return $organization?->name ?? '';
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
            $entry = $this->item($item);
            if ($item['key'] === 'messages' && ($membership = $this->tenant->membership()) !== null) {
                $unread = \App\Domain\Messaging\UnreadCount::for($membership);
                $entry['badge'] = $unread > 0 ? ($unread > 99 ? '99+' : (string) $unread) : null;
            }
            $items[] = $entry;
        }

        return $items;
    }

    /**
     * Pages outside any organization with nothing to borrow (several organizations and none worked in yet, or none):
     * one way back — "Home" resolves to the dashboard, the organization chooser or the console as fits the user.
     *
     * @return list<array<string, mixed>>
     */
    private function homeNav(?User $user): array
    {
        if ($user === null || ! Route::has('home') || ! $this->request->routeIs('account.*')) {
            return [];
        }

        return [$this->item(['key' => 'home', 'label' => 'Home', 'icon' => 'house', 'route' => 'home', 'active' => ['home']])];
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
