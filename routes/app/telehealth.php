<?php

use App\Http\Controllers\App\Telehealth\DeviceCheckController;
use App\Http\Controllers\App\Telehealth\NotesController;
use App\Http\Controllers\App\Telehealth\RecordingController;
use App\Http\Controllers\App\Telehealth\SessionActionController;
use App\Http\Controllers\App\Telehealth\SessionController;
use App\Http\Controllers\App\Telehealth\TelehealthSettingsController;
use App\Http\Controllers\App\Telehealth\TranscriptController;
use App\Models\TelehealthSession;
use Illuminate\Support\Facades\Route;

// Staff application: telehealth module. Loaded inside the /o/{organization} group (middleware: auth, verified,
// tenant; name prefix "app."). Owned by the telehealth module.
//
// Guarded twice: the plan must include telehealth (`feature:telehealth`) AND the member must be allowed
// (`can:` → TelehealthSessionPolicy). Everything under telehealth/{session} starts from a policy check: a
// session outside what the member may see is a 404, one without the needed permission a 403. Only the join
// page and the device check opt in to camera and microphone (`media` default), and only the call page may frame
// Daily and delegate camera/microphone/screen sharing to that frame (`video_call` default); both are read by
// SecurityHeaders. Daily's webhook is not here: routes/webhooks.php (no session, no CSRF, signature-checked).

Route::middleware('feature:telehealth')->group(function () {
    Route::prefix('telehealth')->name('telehealth.')->group(function () {
        Route::get('/', [SessionController::class, 'index'])->middleware('can:viewAny,'.TelehealthSession::class)->name('index');
        Route::get('device-check', [DeviceCheckController::class, 'show'])->defaults('media', true)
            ->middleware('can:viewAny,'.TelehealthSession::class)->name('check');

        Route::prefix('{session}')->scopeBindings()->group(function () {
            Route::get('/', [SessionController::class, 'show'])->middleware('can:view,session')->name('show');
            Route::get('join', [SessionController::class, 'join'])->defaults('media', true)->middleware('can:join,session')->name('join');
            Route::get('call', [SessionController::class, 'call'])->defaults('video_call', true)
                ->middleware(['can:join,session', 'throttle:30,1'])->name('call');

            Route::middleware('can:join,session')->group(function () {
                Route::post('start', [SessionActionController::class, 'start'])->middleware('throttle:30,1')->name('start');
                Route::post('end', [SessionActionController::class, 'end'])->middleware('throttle:30,1')->name('end');
            });

            Route::middleware('can:clinical,session')->group(function () {
                Route::put('consent', [SessionActionController::class, 'consent'])->middleware('throttle:30,1')->name('consent');
                Route::put('notes', [NotesController::class, 'update'])->middleware('throttle:60,1')->name('notes.update');
                Route::post('recordings', [RecordingController::class, 'store'])->middleware('throttle:10,1')->name('recordings.store');
                Route::get('recordings/{recording}', [RecordingController::class, 'download'])->middleware('throttle:60,1')->name('recordings.download');
                Route::get('transcripts/{transcript}', [TranscriptController::class, 'show'])->name('transcripts.show');
                Route::post('transcripts/{transcript}/review', [TranscriptController::class, 'review'])->middleware('throttle:30,1')->name('transcripts.review');
            });
        });
    });

    Route::middleware('can:telehealth.manage')->prefix('settings/telehealth')->name('settings.telehealth.')->group(function () {
        Route::get('/', [TelehealthSettingsController::class, 'edit'])->name('edit');
        Route::put('/', [TelehealthSettingsController::class, 'update'])->middleware('throttle:30,1')->name('update');
    });
});
