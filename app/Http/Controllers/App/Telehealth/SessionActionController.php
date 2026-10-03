<?php

namespace App\Http\Controllers\App\Telehealth;

use App\Domain\Telehealth\EndSession;
use App\Domain\Telehealth\OpenSession;
use App\Domain\Telehealth\RecordConsent;
use App\Domain\Telehealth\SetMeetingLink;
use App\Http\Controllers\Controller;
use App\Http\Requests\Telehealth\ConsentRequest;
use App\Http\Requests\Telehealth\SetLinkRequest;
use App\Models\TelehealthSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** State changes of one session. Thin: authorize (route), call the domain action, answer. */
final class SessionActionController extends Controller
{
    /** Called by the join button as the meeting opens in a new tab; JSON for the script, a redirect without it. */
    public function start(Request $request, TelehealthSession $session, OpenSession $open): JsonResponse|RedirectResponse
    {
        $open($session, $request->user());

        return $request->expectsJson()
            ? response()->json(['status' => $session->status->value])
            : redirect()->route('app.telehealth.join', ['session' => $session])->with('success', 'The session is in progress.');
    }

    public function end(Request $request, TelehealthSession $session, EndSession $end): RedirectResponse
    {
        $end($session, $request->user());

        return redirect()->route('app.telehealth.show', ['session' => $session])->with('success', 'The session was ended.');
    }

    public function link(SetLinkRequest $request, TelehealthSession $session, SetMeetingLink $set): RedirectResponse
    {
        $set($session, (string) $request->validated('join_url'), $request->user());

        return redirect()->route('app.telehealth.join', ['session' => $session])->with('success', 'The meeting link was saved.');
    }

    public function consent(ConsentRequest $request, TelehealthSession $session, RecordConsent $record): RedirectResponse
    {
        $record($session, $request->boolean('consent'), $request->user());

        return back()->with('success', $request->boolean('consent') ? 'Consent to record was noted.' : 'Consent to record was withdrawn.');
    }
}
