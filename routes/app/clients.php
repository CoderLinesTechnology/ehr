<?php

use App\Http\Controllers\App\Clients\ClientAppointmentController;
use App\Http\Controllers\App\Clients\ClientContactController;
use App\Http\Controllers\App\Clients\ClientController;
use App\Http\Controllers\App\Clients\ClientStatusController;
use App\Http\Controllers\App\Clients\ClientTimelineController;
use App\Http\Controllers\App\SearchController;
use App\Models\Client;
use Illuminate\Support\Facades\Route;

// Staff application: clients module. Loaded inside the /o/{organization} group
// (middleware: auth, verified, tenant; name prefix "app."). Owned by the clients module.
//
// Every route is guarded twice: the plan must include the clients module
// (`feature:clients`) AND the member must be allowed (`can:` → ClientPolicy).
// Everything under clients/{client} starts from `can:view,client`: a client
// outside the member's visibility is a 404, so a route added to this group is
// protected by default. There is no route that deletes a client.

Route::middleware('feature:clients')->group(function () {
    Route::get('clients', [ClientController::class, 'index'])
        ->middleware('can:viewAny,'.Client::class)->name('clients.index');
    Route::get('clients/create', [ClientController::class, 'create'])
        ->middleware('can:create,'.Client::class)->name('clients.create');
    Route::post('clients', [ClientController::class, 'store'])
        ->middleware('can:create,'.Client::class)->name('clients.store');

    Route::prefix('clients/{client}')->middleware('can:view,client')->scopeBindings()->group(function () {
        Route::get('/', [ClientController::class, 'show'])->name('clients.show');
        Route::get('edit', [ClientController::class, 'edit'])->middleware('can:update,client')->name('clients.edit');
        Route::put('/', [ClientController::class, 'update'])->middleware('can:update,client')->name('clients.update');

        Route::get('appointments', ClientAppointmentController::class)->middleware('feature:calendar')->name('clients.appointments');
        Route::get('timeline', ClientTimelineController::class)->name('clients.timeline');

        Route::get('contacts', [ClientContactController::class, 'index'])->name('clients.contacts.index');
        Route::post('contacts', [ClientContactController::class, 'store'])->middleware('can:update,client')->name('clients.contacts.store');
        Route::get('contacts/{contact}/edit', [ClientContactController::class, 'edit'])->middleware('can:update,client')->name('clients.contacts.edit');
        Route::put('contacts/{contact}', [ClientContactController::class, 'update'])->middleware('can:update,client')->name('clients.contacts.update');
        Route::delete('contacts/{contact}', [ClientContactController::class, 'destroy'])->middleware('can:update,client')->name('clients.contacts.destroy');

        // Who may change a status depends on the target (archive vs. the rest): decided by the form request.
        Route::post('status', ClientStatusController::class)->name('clients.status');
    });
});

// Global search. The 120/min limit has its own bucket: unnamed throttles share
// one counter per user, so without the prefix this would eat other routes' budget.
Route::get('search', SearchController::class)->middleware('throttle:120,1,search:')->name('search');
