<?php

namespace App\Http\Controllers\App\Settings;

use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Models\OrganizationMembership;
use App\Models\Role;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * The Settings section list (left card), in the comp's order. A section appears only when its route
 * exists (other modules add theirs later), the plan includes its module and the member may open it.
 */
final class SettingsSections
{
    /** @return list<array{key: string, label: string, icon: string, url: string, active: bool, title: string, description: string}> */
    public static function visible(?string $current = null): array
    {
        $organization = tenant()->organization();
        $entitlements = app(EntitlementService::class);
        $has = static fn (string $feature): bool => $organization !== null && $entitlements->allows($organization, $feature);
        $first = static fn (string $prefix): ?string => collect(Route::getRoutes()->getRoutesByName())
            ->keys()->first(fn (string $name) => str_starts_with($name, $prefix));

        $definitions = [
            ['organization', 'Organization', 'building-2', 'app.settings.organization.edit', fn () => Gate::allows('organization.settings.manage'),
                'Organization Settings', "Update your organization's basic information and branding."],
            ['locations', 'Locations', 'map-pin', 'app.settings.locations.index', fn () => Gate::allows('locations.manage'),
                'Locations', 'Where you see clients: addresses, time zones and opening hours.'],
            ['team', 'Team', 'users', 'app.settings.team.index', fn () => Gate::allows('viewAny', OrganizationMembership::class),
                'Team', 'Invite colleagues, set their roles and calendar colours.'],
            ['roles', 'Roles & Permissions', 'shield-check', 'app.settings.roles.index', fn () => Gate::allows('viewAny', Role::class),
                'Roles & Permissions', 'Decide what each role is allowed to do.'],
            ['scheduling', 'Scheduling', 'calendar', 'app.settings.scheduling.edit', fn () => Gate::allows('organization.settings.manage') && $has(FeatureRegistry::CALENDAR),
                'Scheduling', 'Defaults for appointment length, booking rules and the calendar.'],
            ['availability', 'Availability', 'calendar-clock', $first('app.settings.availability.'), fn () => Gate::any(['availability.manage_own', 'availability.manage_all']),
                'Availability', 'Working hours and time off.'],
            ['services', 'Services', 'layout-list', 'app.settings.services.index', fn () => Gate::allows('services.manage'),
                'Services', 'What you offer, how long it takes and what it costs.'],
            ['clients', 'Clients', 'user', 'app.settings.clients.edit', fn () => Gate::allows('organization.settings.manage') && $has(FeatureRegistry::CLIENTS),
                'Client Settings', 'What you require when a new client is registered.'],
            ['telehealth', 'Telehealth', 'video', 'app.settings.telehealth.edit', fn () => Gate::allows('telehealth.manage') && $has(FeatureRegistry::TELEHEALTH),
                'Telehealth', 'Meeting links, join window, recording and AI transcript options.'],
            ['audit', 'Audit log', 'scroll-text', 'app.settings.audit.index', fn () => Gate::allows('audit.view'),
                'Audit log', 'Who did what, and when, in this organization.'],
            ['subscription', 'Subscription & usage', 'credit-card', 'app.settings.subscription.show', fn () => Gate::allows('organization.settings.manage'),
                'Subscription & usage', 'Your plan, its limits and how much of each you use.'],
            ['demo', 'Demo data', 'flask-conical', $first('app.settings.demo.'), fn () => Gate::allows('demo.manage'),
                'Demo data', 'Sample records for trying things out.'],
        ];

        $sections = [];
        foreach ($definitions as [$key, $label, $icon, $route, $allowed, $title, $description]) {
            if ($route === null || ! Route::has($route) || ! $allowed()) {
                continue;
            }
            $sections[] = [
                'key' => $key, 'label' => $label, 'icon' => $icon, 'url' => route($route),
                'active' => $key === $current, 'title' => $title, 'description' => $description,
            ];
        }

        return $sections;
    }
}
