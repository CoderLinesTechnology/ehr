<?php

use App\Http\Controllers\App\Settings\AuditController;
use App\Http\Controllers\App\Settings\GroupSettingsController;
use App\Http\Controllers\App\Settings\LocationController;
use App\Http\Controllers\App\Settings\OrganizationController;
use App\Http\Controllers\App\Settings\RoleController;
use App\Http\Controllers\App\Settings\ServiceController;
use App\Http\Controllers\App\Settings\SettingsController;
use App\Http\Controllers\App\Settings\SubscriptionController;
use App\Http\Controllers\App\Settings\TeamController;
use App\Models\OrganizationMembership;
use App\Models\Role;
use Illuminate\Support\Facades\Route;

// Staff application: settings module. Loaded inside the /o/{organization} group
// (middleware: auth, verified, tenant; name prefix "app."). Owned by the settings module.
//
// Every route is guarded by a permission (`can:`), by the plan where the section belongs to a module
// (`feature:`), and every record is looked up through the tenant scope: another organization's id is a 404.
// The permission is code (PermissionRegistry); entitlement is not permission, a section needs both.

Route::prefix('settings')->name('settings.')->scopeBindings()->group(function () {
    Route::get('/', SettingsController::class)->name('index');

    // Organization (profile, general information, branding, contact) and its logo.
    Route::middleware('can:organization.settings.manage')->group(function () {
        Route::get('organization', [OrganizationController::class, 'edit'])->name('organization.edit');
        Route::put('organization', [OrganizationController::class, 'update'])->name('organization.update');
        Route::post('organization/logo', [OrganizationController::class, 'storeLogo'])->middleware('throttle:20,1')->name('organization.logo.store');
    });
    // Any member of the organization may see its logo (it is in the shell); the tenant middleware has already checked membership.
    Route::get('organization/logo', [OrganizationController::class, 'logo'])->name('organization.logo');

    Route::middleware('can:locations.manage')->group(function () {
        Route::get('locations', [LocationController::class, 'index'])->name('locations.index');
        Route::get('locations/create', [LocationController::class, 'create'])->name('locations.create');
        Route::post('locations', [LocationController::class, 'store'])->name('locations.store');
        Route::get('locations/{location}/edit', [LocationController::class, 'edit'])->name('locations.edit');
        Route::put('locations/{location}', [LocationController::class, 'update'])->name('locations.update');
        Route::patch('locations/{location}/status', [LocationController::class, 'status'])->name('locations.status');
    });

    Route::middleware('can:services.manage')->group(function () {
        Route::get('services', [ServiceController::class, 'index'])->name('services.index');
        Route::get('services/create', [ServiceController::class, 'create'])->name('services.create');
        Route::post('services', [ServiceController::class, 'store'])->name('services.store');
        Route::get('services/{service}/edit', [ServiceController::class, 'edit'])->name('services.edit');
        Route::put('services/{service}', [ServiceController::class, 'update'])->name('services.update');
        Route::patch('services/{service}/status', [ServiceController::class, 'status'])->name('services.status');
    });

    Route::prefix('team')->name('team.')->group(function () {
        Route::get('/', [TeamController::class, 'index'])->middleware('can:viewAny,'.OrganizationMembership::class)->name('index');
        Route::post('invitations', [TeamController::class, 'invite'])->middleware(['can:invite,'.OrganizationMembership::class, 'throttle:30,1'])->name('invite');
        Route::post('invitations/{invitation}/resend', [TeamController::class, 'resend'])->middleware(['can:resendInvitation,invitation', 'throttle:30,1'])->name('invitations.resend');
        Route::delete('invitations/{invitation}', [TeamController::class, 'revoke'])->middleware('can:revokeInvitation,invitation')->name('invitations.revoke');
        Route::get('{member}/edit', [TeamController::class, 'edit'])->middleware('can:view,member')->name('edit');
        Route::put('{member}', [TeamController::class, 'update'])->middleware('can:update,member')->name('update');
        Route::patch('{member}/status', [TeamController::class, 'status'])->middleware('can:changeStatus,member')->name('status');
    });

    Route::prefix('roles')->name('roles.')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->middleware('can:viewAny,'.Role::class)->name('index');
        Route::get('create', [RoleController::class, 'create'])->middleware('can:create,'.Role::class)->name('create');
        Route::post('/', [RoleController::class, 'store'])->middleware('can:create,'.Role::class)->name('store');
        Route::get('{role}/edit', [RoleController::class, 'edit'])->middleware('can:view,role')->name('edit');
        Route::put('{role}', [RoleController::class, 'update'])->middleware('can:update,role')->name('update');
        Route::delete('{role}', [RoleController::class, 'destroy'])->middleware('can:delete,role')->name('destroy');
    });

    Route::middleware(['can:organization.settings.manage', 'feature:calendar'])->group(function () {
        Route::get('scheduling', [GroupSettingsController::class, 'edit'])->defaults('group', 'scheduling')->name('scheduling.edit');
        Route::put('scheduling', [GroupSettingsController::class, 'update'])->defaults('group', 'scheduling')->name('scheduling.update');
    });

    Route::middleware(['can:organization.settings.manage', 'feature:clients'])->group(function () {
        Route::get('clients', [GroupSettingsController::class, 'edit'])->defaults('group', 'clients')->name('clients.edit');
        Route::put('clients', [GroupSettingsController::class, 'update'])->defaults('group', 'clients')->name('clients.update');
    });

    Route::get('audit', [AuditController::class, 'index'])->middleware('can:audit.view')->name('audit.index');
    Route::get('subscription', [SubscriptionController::class, 'show'])->middleware('can:organization.settings.manage')->name('subscription.show');
});
