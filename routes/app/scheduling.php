<?php

use App\Http\Controllers\App\Scheduling\AppointmentController;
use App\Http\Controllers\App\Scheduling\AvailabilityController;
use App\Http\Controllers\App\Scheduling\CalendarController;
use App\Models\Appointment;
use Illuminate\Support\Facades\Route;

// Staff application: scheduling module. Loaded inside the /o/{organization} group
// (middleware: auth, verified, tenant; name prefix "app."). Owned by the scheduling module.
//
// Guarded twice: the plan must include the calendar module (`feature:calendar`) AND the member must
// be allowed (`can:` → AppointmentPolicy). Everything under appointments/{appointment} starts from
// `can:view,appointment`: an appointment outside the member's visibility is a 404. Who may change it
// (edit vs. cancel) is decided per action, because a status change depends on its target.

Route::middleware('feature:calendar')->group(function () {
    Route::get('calendar', CalendarController::class)
        ->middleware('can:viewAny,'.Appointment::class)->name('calendar.index');

    Route::get('appointments/create', [AppointmentController::class, 'create'])
        ->middleware('can:create,'.Appointment::class)->name('appointments.create');
    Route::post('appointments', [AppointmentController::class, 'store'])
        ->middleware('can:create,'.Appointment::class)->name('appointments.store');

    Route::prefix('appointments/{appointment}')->middleware('can:view,appointment')->group(function () {
        Route::get('/', [AppointmentController::class, 'show'])->name('appointments.show');
        Route::put('/', [AppointmentController::class, 'update'])->middleware('can:update,appointment')->name('appointments.update');
        Route::post('transition', [AppointmentController::class, 'transition'])->name('appointments.transition');
        Route::post('reschedule', [AppointmentController::class, 'reschedule'])->middleware('can:update,appointment')->name('appointments.reschedule');
    });

    // Availability: own with `availability.manage_own`, everyone's with `availability.manage_all` (decided in the controller).
    Route::prefix('settings/availability')->name('settings.availability.')->group(function () {
        Route::get('/', [AvailabilityController::class, 'index'])->name('index');
        Route::post('rules', [AvailabilityController::class, 'storeRule'])->name('rules.store');
        Route::put('rules/{rule}', [AvailabilityController::class, 'updateRule'])->name('rules.update');
        Route::delete('rules/{rule}', [AvailabilityController::class, 'destroyRule'])->name('rules.destroy');
        Route::post('blocked', [AvailabilityController::class, 'storeBlocked'])->name('blocked.store');
        Route::delete('blocked/{blocked}', [AvailabilityController::class, 'destroyBlocked'])->name('blocked.destroy');
    });
});
