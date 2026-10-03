<?php

use App\Http\Controllers\Invitations\InvitationController;
use Illuminate\Support\Facades\Route;

// Staff invitation links. This file is loaded inside the `auth` group (so acceptance needs a signed-in
// user, but not a verified email: a freshly registered invitee must be able to accept). Viewing the
// link is the one exception: a visitor who is not signed in is sent to the sign-in page by the
// controller itself, after remembering the invited address for registration.
// Throttled: the token is a secret and these routes answer differently for good and bad ones.

Route::middleware('throttle:20,1')->group(function () {
    Route::withoutMiddleware('auth')->get('invitations/{token}', [InvitationController::class, 'show'])
        ->where('token', '[A-Za-z0-9_-]{16,200}')->name('invitations.show');

    Route::post('invitations/{token}', [InvitationController::class, 'accept'])
        ->where('token', '[A-Za-z0-9_-]{16,200}')->name('invitations.accept');
});
