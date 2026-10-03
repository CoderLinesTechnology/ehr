<?php

use App\Http\Controllers\App\Programs\EnrollmentController;
use App\Http\Controllers\App\Programs\LevelOfCareController;
use App\Http\Controllers\App\Programs\ProgramController;
use App\Http\Controllers\App\Programs\SessionController;
use App\Http\Controllers\App\Programs\StaffController;
use App\Models\Program;
use Illuminate\Support\Facades\Route;

// Staff application: programs and levels of care. Loaded inside the /o/{organization} group (middleware: auth,
// verified, tenant; name prefix "app."). Owned by the programs module.
//
// Entitlement `programs` gates the module; `levels_of_care` the levels routes, `groups` the schedule routes. Every
// route also needs a permission (programs.view | manage | enroll | view_sud) through the policies: a program of
// another organization is a 404 at binding, a participant outside what the member may see (42 CFR Part 2 program
// without programs.view_sud, or a client they may not see) is a 404, a missing permission is a 403.

Route::prefix('programs')->name('programs.')->middleware('feature:programs')->group(function () {
    Route::get('/', [ProgramController::class, 'index'])->middleware('can:viewAny,'.Program::class)->name('index');

    Route::middleware('can:create,'.Program::class)->group(function () {
        Route::get('create', [ProgramController::class, 'create'])->name('create');
        Route::post('/', [ProgramController::class, 'store'])->middleware('throttle:30,1')->name('store');
    });

    Route::middleware('can:programs.enroll')->group(function () {
        Route::get('admit', [EnrollmentController::class, 'create'])->name('admit');
        Route::post('admit', [EnrollmentController::class, 'store'])->middleware('throttle:30,1')->name('admit.store');
    });

    Route::prefix('{program}')->scopeBindings()->group(function () {
        Route::get('/', [ProgramController::class, 'show'])->middleware('can:view,program')->name('show');
        Route::get('edit', [ProgramController::class, 'edit'])->middleware('can:update,program')->name('edit');
        Route::put('/', [ProgramController::class, 'update'])->middleware(['can:update,program', 'throttle:30,1'])->name('update');
        Route::post('status', [ProgramController::class, 'status'])->middleware(['can:manage,program', 'throttle:30,1'])->name('status');

        Route::middleware(['can:manage,program', 'feature:levels_of_care', 'throttle:60,1'])->group(function () {
            Route::post('levels', [LevelOfCareController::class, 'store'])->name('levels.store');
            Route::put('levels/{level}', [LevelOfCareController::class, 'update'])->name('levels.update');
        });

        Route::middleware(['can:manage,program', 'throttle:60,1'])->group(function () {
            Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
            Route::delete('staff/{staff}', [StaffController::class, 'destroy'])->name('staff.destroy');
        });

        Route::middleware('feature:groups')->group(function () {
            Route::post('sessions', [SessionController::class, 'store'])->middleware(['can:manage,program', 'throttle:60,1'])->name('sessions.store');
            Route::get('sessions/{session}', [SessionController::class, 'show'])->middleware('can:view,program')->name('sessions.show');
            Route::post('sessions/{session}/attendance', [SessionController::class, 'attendance'])->middleware(['can:admit,program', 'throttle:60,1'])->name('sessions.attendance');
            Route::post('sessions/{session}/cancel', [SessionController::class, 'cancel'])->middleware(['can:manage,program', 'throttle:60,1'])->name('sessions.cancel');
        });

        Route::prefix('enrollments/{enrollment}')->name('enrollments.')->group(function () {
            Route::get('/', [EnrollmentController::class, 'show'])->middleware('can:view,enrollment')->name('show');
            Route::middleware(['can:manage,enrollment', 'throttle:60,1'])->group(function () {
                Route::post('level', [EnrollmentController::class, 'level'])->name('level');
                Route::post('hold', [EnrollmentController::class, 'hold'])->name('hold');
                Route::post('resume', [EnrollmentController::class, 'resume'])->name('resume');
                Route::post('discharge', [EnrollmentController::class, 'discharge'])->name('discharge');
                Route::post('transfer', [EnrollmentController::class, 'transfer'])->name('transfer');
            });
        });
    });
});
