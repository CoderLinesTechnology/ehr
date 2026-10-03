<?php

use App\Http\Controllers\Webhooks\DailyWebhookController;
use Illuminate\Support\Facades\Route;

// Provider callbacks. Registered from bootstrap/app.php OUTSIDE the `web` group: no session, no cookies, no CSRF
// token, no sign-in and no tenant — each controller authenticates the sender by its signature before reading
// anything. Throttled per sender address.

Route::post('webhooks/daily', DailyWebhookController::class)->middleware('throttle:120,1')->name('webhooks.daily');
