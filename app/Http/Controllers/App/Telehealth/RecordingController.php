<?php

namespace App\Http\Controllers\App\Telehealth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Telehealth\AttachRecording;
use App\Http\Controllers\Controller;
use App\Http\Requests\Telehealth\RecordingRequest;
use App\Models\SessionRecording;
use App\Models\TelehealthSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RecordingController extends Controller
{
    public function store(RecordingRequest $request, TelehealthSession $session, AttachRecording $attach): RedirectResponse
    {
        $attach($session, $request->file('recording'), null, $request->user());

        return redirect()->route('app.telehealth.show', ['session' => $session])->with('success', 'The recording was attached.');
    }

    /**
     * Streams the recording to someone the route authorized (`can:clinical` on its session). The path comes
     * from the row, never from the URL; every download is audited; nothing is cached anywhere.
     */
    public function download(TelehealthSession $session, SessionRecording $recording, AuditLogger $audit): StreamedResponse
    {
        abort_if($recording->purged_at !== null, 404);

        $disk = Storage::disk(AttachRecording::DISK);
        abort_unless(str_starts_with($recording->storage_path, "telehealth/{$session->organization_id}/{$session->id}/") && $disk->exists($recording->storage_path), 404);

        $audit->record(
            'telehealth.recording_downloaded',
            subject: $recording,
            metadata: ['size_bytes' => $recording->size_bytes],
            summary: 'A telehealth recording was downloaded',
        );

        $extension = pathinfo($recording->storage_path, PATHINFO_EXTENSION);

        return $disk->download($recording->storage_path, 'session-recording.'.$extension, [
            'Content-Type' => $recording->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
