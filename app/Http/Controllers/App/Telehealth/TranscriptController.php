<?php

namespace App\Http\Controllers\App\Telehealth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Telehealth\ReviewTranscript;
use App\Http\Controllers\Controller;
use App\Models\SessionTranscript;
use App\Models\TelehealthSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Reading a transcript (audited) and the clinician's review of it. A draft stays labelled "Draft" until reviewed. */
final class TranscriptController extends Controller
{
    public function show(TelehealthSession $session, SessionTranscript $transcript, AuditLogger $audit): View
    {
        $audit->record(
            'telehealth.transcript_viewed',
            subject: $transcript,
            metadata: ['source' => $transcript->source->value, 'status' => $transcript->status->value],
            summary: 'A telehealth transcript was opened',
        );

        return view('app.telehealth.transcript', [
            'session' => $session,
            'transcript' => $transcript,
            // The body is a hidden attribute (never serialised by accident); the page reads it explicitly.
            'body' => $transcript->getAttributeValue('body'),
        ]);
    }

    public function review(Request $request, TelehealthSession $session, SessionTranscript $transcript, ReviewTranscript $review): RedirectResponse
    {
        $review($transcript, $request->user());

        return redirect()->route('app.telehealth.transcripts.show', ['session' => $session, 'transcript' => $transcript])
            ->with('success', 'The transcript was marked as reviewed.');
    }
}
