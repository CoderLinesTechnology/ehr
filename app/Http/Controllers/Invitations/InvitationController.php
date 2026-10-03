<?php

namespace App\Http\Controllers\Invitations;

use App\Actions\Fortify\CreateNewUser;
use App\Domain\Identity\AcceptInvitation;
use App\Domain\Identity\AcceptedInvitation;
use App\Domain\Identity\FindInvitation;
use App\Domain\Shared\DomainException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Staff invitation links: /invitations/{token}. The token (not the URL's organization) identifies the
 * invitation; unknown, expired and revoked links all show the same neutral page. A visitor who is not
 * signed in is sent to sign in, and the invited address is remembered in the session so that person
 * may register even when self-service registration is closed.
 */
final class InvitationController extends Controller
{
    public function show(Request $request, string $token, FindInvitation $find): Response|View|RedirectResponse
    {
        $invite = $find($token);

        if ($invite === null) {
            return response()->view('invitations.unavailable', [], 404);
        }

        $user = $request->user();
        if ($user === null) {
            $request->session()->put(CreateNewUser::INVITATION_SESSION_KEY, mb_strtolower((string) $invite->invited_email));

            return redirect()->guest(route('login'))->with('info', 'Sign in or create an account with '.$invite->invited_email.' to accept your invitation.');
        }

        return view('invitations.show', [
            'invitation' => $find->details($invite),
            'token' => $token,
            'emailMatches' => $find->emailMatches($invite, $user),
            'userEmail' => $user->email,
        ]);
    }

    public function accept(Request $request, string $token, AcceptInvitation $accept): RedirectResponse
    {
        try {
            $accepted = $accept($token, $request->user());
        } catch (DomainException $e) {
            if ($e->errorCode() === 'invitation_unavailable') {
                return redirect()->route('invitations.show', ['token' => $token]);
            }

            throw $e;
        }

        $request->session()->forget(CreateNewUser::INVITATION_SESSION_KEY);

        $message = match ($accepted->outcome) {
            AcceptedInvitation::ALREADY_MEMBER => 'You already belong to '.$accepted->organization->name.'.',
            AcceptedInvitation::REJOINED => 'Welcome back to '.$accepted->organization->name.'.',
            default => 'Welcome to '.$accepted->organization->name.'.',
        };

        return redirect()->route('app.dashboard', ['organization' => $accepted->organization->slug])->with('success', $message);
    }
}
