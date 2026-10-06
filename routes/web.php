<?php

use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Onboarding\CreateOrganizationController;
use App\Http\Controllers\OrganizationChooserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Route map
|--------------------------------------------------------------------------
| /                       → sign-in or the user's home
| /home                   → resolves where a signed-in user belongs
| /account/…              → the user's own account (routes/account.php)
| /onboarding/…           → create the first organization after registering
| /o/{organization}/…     → staff application, tenant resolved from the URL
| /platform/…             → Super Admin console (routes/platform.php)
| /invitations/…          → staff invitations (routes/invitations.php)
*/

Route::get('/', [HomeController::class, 'root'])->name('root');

Route::middleware('auth')->group(function () {
    require __DIR__.'/invitations.php';
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/home', [HomeController::class, 'home'])->name('home');
    Route::get('/organizations', OrganizationChooserController::class)->name('organizations.choose');

    Route::get('/onboarding/organization', [CreateOrganizationController::class, 'create'])->name('onboarding.organization.create');
    Route::post('/onboarding/organization', [CreateOrganizationController::class, 'store'])
        ->middleware('throttle:10,60')
        ->name('onboarding.organization.store');

    Route::prefix('account')->name('account.')->group(__DIR__.'/account.php');

    // Staff application. Every route here runs inside the organization from the URL.
    Route::prefix('o/{organization}')->name('app.')->middleware('tenant')->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        require __DIR__.'/app/clients.php';
        require __DIR__.'/app/scheduling.php';
        require __DIR__.'/app/messages.php';
        require __DIR__.'/app/settings.php';
        require __DIR__.'/app/resources.php';
        require __DIR__.'/app/programs.php';
        require __DIR__.'/app/telehealth.php';
        require __DIR__.'/app/demo.php';
        require __DIR__.'/app/tasks.php';
        require __DIR__.'/app/documents.php';
        require __DIR__.'/app/reports.php';
    });

    Route::prefix('platform')->name('platform.')->middleware('platform')->group(__DIR__.'/platform.php');
});

if (app()->isLocal() && file_exists(__DIR__.'/dev.php')) {
    require __DIR__.'/dev.php';
}
