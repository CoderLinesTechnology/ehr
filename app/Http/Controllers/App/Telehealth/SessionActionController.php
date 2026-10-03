<?php

namespace App\Http\Controllers\App\Telehealth;

use App\Domain\Telehealth\EndSession;
use App\Domain\Telehealth\RecordConsent;
use App\Domain\Telehealth\StartCall;
use App\Http\Controllers\Controller;
use App\Http\Requests\Telehealth\ConsentRequest;
use App\Models\TelehealthSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** State changes of one session. Thin: authorize (route), call the domain action, answer. */
final class SessionActionController extends Controller
{
    /** Session key holding a staff member's camera/microphone choice for one call (read by SessionController::call). */
    public static function devicesKey(TelehealthSession $session): string
    {
        return 'telehealth.devices.'.$session->id;
    }

    /** "Join Session" (a POST form): the room must be available, the session opens, the call page follows. */
    public function start(Request $request, TelehealthSession $session, StartCall $start): JsonResponse|RedirectResponse
    {
        $start($session, $request->user());
        // The camera / microphone choice from the join page's preview (on unless switched off there). Kept for this
        // session in this browser, so a refresh of the call page joins the same way.
        $request->session()->put(self::devicesKey($session), [
            'camera_off' => $request->has('camera') && ! $request->boolean('camera'),
            'microphone_off' => $request->has('microphone') && ! $request->boolean('microphone'),
        ]);
        $call = route('app.telehealth.call', ['session' => $session]);

        return $request->expectsJson()
            ? response()->json(['status' => $session->status->value, 'call' => $call])
            : redirect()->to($call);
    }

    public function end(Request $request, TelehealthSession $session, EndSession $end): RedirectResponse
    {
        $end($session, $request->user());

        return redirect()->route('app.telehealth.show', ['session' => $session])->with('success', 'The session was ended.');
    }

    public function consent(ConsentRequest $request, TelehealthSession $session, RecordConsent $record): RedirectResponse
    {
        $record($session, $request->boolean('consent'), $request->user());

        return back()->with('success', $request->boolean('consent') ? 'Consent to record was noted.' : 'Consent to record was withdrawn.');
    }
}
