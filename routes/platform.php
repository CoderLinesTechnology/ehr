<?php

use App\Http\Controllers\Platform\AdminController;
use App\Http\Controllers\Platform\AuditLogController;
use App\Http\Controllers\Platform\DashboardController;
use App\Http\Controllers\Platform\OrganizationController;
use App\Http\Controllers\Platform\OrganizationEntitlementController;
use App\Http\Controllers\Platform\OrganizationStatusController;
use App\Http\Controllers\Platform\OrganizationSubscriptionController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Platform\SettingsController;
use App\Http\Controllers\Platform\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Super Admin console
|--------------------------------------------------------------------------
| Loaded with prefix /platform and name prefix "platform.", behind the
| middleware auth, verified and platform (a platform role AND confirmed 2FA).
|
| This file is also the permission map: every route names the platform.*
| permission it needs (`can:`), and every controller action authorizes the
| same permission again with Gate::authorize(). Routes that remove or change
| access also require a recent password confirmation (`password.confirm`),
| placed AFTER `can:` so a person who lacks the permission gets a 403 and is
| never asked for their password first.
|
| Nothing here reads tenant records: screens show organization profile,
| status, plan and subscription, entitlements, and aggregate COUNTS.
*/

// ── Dashboard ────────────────────────────────────────────────────────────
Route::get('/', DashboardController::class)
    ->middleware('can:platform.dashboard.view')
    ->name('dashboard');

// ── Organizations ────────────────────────────────────────────────────────
Route::prefix('organizations')->name('organizations.')->group(function () {
    Route::get('/', [OrganizationController::class, 'index'])
        ->middleware('can:platform.organizations.view')
        ->name('index');

    // Must come before {organization}, or "create" would be read as a slug.
    Route::middleware('can:platform.organizations.manage')->group(function () {
        Route::get('create', [OrganizationController::class, 'create'])->name('create');
        Route::post('/', [OrganizationController::class, 'store'])
            ->middleware('throttle:20,1,platform-organization-create')
            ->name('store');
    });

    Route::prefix('{organization}')->where(['organization' => '[a-z0-9][a-z0-9-]*'])->group(function () {
        Route::get('/', [OrganizationController::class, 'show'])
            ->middleware('can:platform.organizations.view')
            ->name('show');

        Route::middleware('can:platform.organizations.manage')->group(function () {
            Route::get('edit', [OrganizationController::class, 'edit'])->name('edit');
            Route::put('/', [OrganizationController::class, 'update'])->name('update');
        });

        // Lifecycle: suspend, archive, cancel, reactivate.
        Route::post('status', OrganizationStatusController::class)
            ->middleware(['can:platform.organizations.lifecycle', 'password.confirm'])
            ->name('status');

        // Subscription: start one, change plan, change status.
        Route::middleware('can:platform.subscriptions.manage')->prefix('subscription')->name('subscription.')->group(function () {
            Route::post('start', [OrganizationSubscriptionController::class, 'start'])->name('start');

            Route::middleware('password.confirm')->group(function () {
                Route::post('plan', [OrganizationSubscriptionController::class, 'changePlan'])->name('plan');
                Route::post('status', [OrganizationSubscriptionController::class, 'changeStatus'])->name('status');
            });
        });

        // Per-organization overrides of plan features and limits.
        Route::middleware('can:platform.features.manage')->prefix('entitlements')->name('entitlements.')->group(function () {
            Route::post('/', [OrganizationEntitlementController::class, 'store'])->name('store');
            Route::delete('{feature}', [OrganizationEntitlementController::class, 'destroy'])
                ->where('feature', '[a-z0-9_]+')
                ->name('destroy');
        });
    });
});

// ── Plans & features ─────────────────────────────────────────────────────
// Editing a plan changes the features of every organization on it at once,
// so opening and saving the form both need a recent password confirmation
// (the form is long: confirming first means a save is never bounced).
Route::middleware('can:platform.plans.manage')->prefix('plans')->name('plans.')->group(function () {
    Route::get('/', [PlanController::class, 'index'])->name('index');
    Route::get('create', [PlanController::class, 'create'])->name('create');
    Route::post('/', [PlanController::class, 'store'])->name('store');

    Route::middleware('password.confirm')->group(function () {
        Route::get('{plan}/edit', [PlanController::class, 'edit'])->name('edit');
        Route::put('{plan}', [PlanController::class, 'update'])->name('update');
    });
});

// ── Platform settings ────────────────────────────────────────────────────
// Confirm before opening the form, for the same reason as plans.
Route::middleware(['can:platform.settings.manage', 'password.confirm'])->prefix('settings')->name('settings.')->group(function () {
    Route::get('/', [SettingsController::class, 'edit'])->name('edit');
    Route::put('/', [SettingsController::class, 'update'])->name('update');
});

// ── Users ────────────────────────────────────────────────────────────────
Route::prefix('users')->name('users.')->group(function () {
    Route::middleware('can:platform.users.view')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('{user}', [UserController::class, 'show'])->name('show');
    });

    Route::middleware(['can:platform.admins.manage', 'password.confirm'])->group(function () {
        Route::post('{user}/disable', [UserController::class, 'disable'])->name('disable');
        Route::post('{user}/enable', [UserController::class, 'enable'])->name('enable');
    });

    // Support action: sends email to a third party, so it is throttled per administrator (and per account, in the domain action).
    Route::post('{user}/verification', [UserController::class, 'verification'])
        ->middleware(['can:platform.support.act', 'throttle:6,1,platform-verification'])
        ->name('verification');
});

// ── Platform administrators ──────────────────────────────────────────────
Route::middleware('can:platform.admins.manage')->prefix('admins')->name('admins.')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('index');

    Route::middleware('password.confirm')->group(function () {
        // Looks accounts up by email, so it is throttled against address probing.
        Route::post('/', [AdminController::class, 'store'])
            ->middleware('throttle:20,1,platform-admin-grant')
            ->name('store');
        Route::delete('{user}/roles/{role}', [AdminController::class, 'destroy'])
            ->where('role', '[a-z0-9_]+')
            ->name('destroy');
    });
});

// ── Audit log ────────────────────────────────────────────────────────────
Route::get('audit', [AuditLogController::class, 'index'])
    ->middleware('can:platform.audit.view')
    ->name('audit.index');
