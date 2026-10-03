<?php

use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\SecurityController;
use Illuminate\Support\Facades\Route;

// The signed-in user's own account pages (prefix /account, name prefix "account.").
// routes/web.php loads this file inside the auth + verified group.

Route::get('/', [ProfileController::class, 'show'])->name('profile');

Route::put('/preferences', [ProfileController::class, 'updatePreferences'])
    ->middleware('throttle:20,1')
    ->name('preferences.update');

// Shows two-factor setup material and recovery codes, so it needs a recent password confirmation.
// The password.confirm middleware is declared on the controller (SecurityController::middleware()) so that
// it runs after the "MFA required" notice has been kept alive across the confirmation redirect.
Route::get('/security', [SecurityController::class, 'show'])->name('security');
