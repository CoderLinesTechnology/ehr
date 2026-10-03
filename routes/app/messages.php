<?php

use App\Http\Controllers\App\Messages\AttachmentController;
use App\Http\Controllers\App\Messages\ConversationController;
use App\Http\Controllers\App\Messages\MessageController;
use App\Http\Controllers\App\Messages\ParticipantController;
use App\Models\Conversation;
use Illuminate\Support\Facades\Route;

// Staff application: messaging module (inside the /o/{organization} group, names prefixed "app.").
//
// Guarded twice: the plan must include messaging (`feature:messaging`) AND the member must be allowed
// (`can:` → ConversationPolicy). Everything under messages/{conversation} starts from `can:view,conversation`:
// a conversation the member does not take part in is a 404, so a route added to this group is protected by default.

Route::middleware('feature:messaging')->prefix('messages')->name('messages.')->group(function () {
    Route::get('/', [ConversationController::class, 'index'])->middleware('can:viewAny,'.Conversation::class)->name('index');
    Route::post('/', [ConversationController::class, 'store'])->middleware(['can:viewAny,'.Conversation::class, 'throttle:30,1'])->name('store');
    // Declared before {conversation} so "attachments" is never read as a conversation.
    Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');

    Route::middleware('can:view,conversation')->scopeBindings()->group(function () {
        Route::get('{conversation}', [ConversationController::class, 'show'])->name('show');
        Route::get('{conversation}/poll', [ConversationController::class, 'poll'])->middleware('throttle:30,1')->name('poll');
        Route::post('{conversation}/send', [MessageController::class, 'send'])->middleware('throttle:60,1')->name('send');
        Route::post('{conversation}/read', [MessageController::class, 'read'])->middleware('throttle:60,1')->name('read');
        Route::post('{conversation}/messages/{message}/react', [MessageController::class, 'react'])->middleware('throttle:60,1')->name('react');
        Route::post('{conversation}/messages/{message}/retract', [MessageController::class, 'retract'])->middleware('throttle:30,1')->name('retract');
        Route::post('{conversation}/participants', [ParticipantController::class, 'store'])->middleware('throttle:30,1')->name('participants.store');
        Route::post('{conversation}/leave', [ParticipantController::class, 'leave'])->middleware('throttle:30,1')->name('leave');
    });
});
