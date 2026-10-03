<?php

use App\Http\Controllers\App\Resources\ManageResourceController;
use App\Http\Controllers\App\Resources\ResourceController;
use App\Models\Resource;
use Illuminate\Support\Facades\Route;

// Staff application: resources module (guides, forms, documents, videos, FAQs). Loaded inside the
// /o/{organization} group (middleware: auth, verified, tenant; name prefix "app."). Owned by the resources module.
//
// Permission is code (`resources.view`, `resources.manage`), not entitlement: the module has no plan gate.
// Every route that names a resource starts from a policy check (`can:view|file|update|publish|archive,resource`):
// a resource outside what the member may see is a 404; another organization's id is a 404 at binding.

Route::prefix('resources')->name('resources.')->group(function () {
    Route::get('/', [ResourceController::class, 'index'])
        ->middleware('can:viewAny,'.Resource::class)->name('index');

    Route::middleware('can:create,'.Resource::class)->group(function () {
        Route::get('create', [ManageResourceController::class, 'create'])->name('create');
        Route::post('/', [ManageResourceController::class, 'store'])->middleware('throttle:30,1')->name('store');
    });

    Route::prefix('{resource}')->group(function () {
        Route::get('/', [ResourceController::class, 'show'])->middleware('can:view,resource')->name('show');
        Route::get('file', [ResourceController::class, 'file'])->middleware('can:file,resource')->name('file');
        Route::get('edit', [ManageResourceController::class, 'edit'])->middleware('can:update,resource')->name('edit');
        Route::put('/', [ManageResourceController::class, 'update'])->middleware(['can:update,resource', 'throttle:30,1'])->name('update');
        Route::post('publish', [ManageResourceController::class, 'publish'])->middleware('can:publish,resource')->name('publish');
        Route::post('archive', [ManageResourceController::class, 'archive'])->middleware('can:archive,resource')->name('archive');
    });
});
