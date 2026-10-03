<?php

namespace App\Http\Controllers\App\Telehealth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Domain\Telehealth\AttachRecording;
use App\Domain\Telehealth\Providers\ProviderRegistry;
use App\Domain\Telehealth\Providers\VideoServiceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Telehealth\RecordingRequest;
use App\Models\SessionRecording;
use App\Models\TelehealthSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RecordingController extends Controller
{
    /** How long the vendor's download link lives (Daily's minimum). */
    public const LINK_SECONDS = 900;

    public function store(RecordingRequest $request, TelehealthSession $session, AttachRecording $attach): RedirectResponse
    {
        $attach($session, $request->file('recording'), null, $request->user());

        return redirect()->route('app.telehealth.show', ['session' => $session])->with('success', 'The recording was attached.');
    }

    /**
     * Serves the recording to someone the route authorized (`can:clinical` on its session); every download is
     * audited and nothing is cached anywhere. A stored file streams from the private disk (the path comes from the
     * row, never from the URL). A recording the video vendor keeps is a redirect to a download link minted for this
     * request (15 minutes), never stored.
     */
    public function download(TelehealthSession $session, SessionRecording $recording, AuditLogger $audit, ProviderRegistry $providers): StreamedResponse|RedirectResponse
    {
        abort_if($recording->purged_at !== null, 404);

        if ($recording->provider_recording_id !== null) {
            abort_unless(is_string($recording->provider_key) && in_array($recording->provider_key, $providers->keys(), true), 404);

            try {
                $link = $providers->get($recording->provider_key)->recordingDownloadUrl($recording->provider_recording_id, self::LINK_SECONDS);
            } catch (VideoServiceException) {
                throw new DomainException('The recording could not be fetched from the video service. Try again in a moment.', 'recording_unavailable', 'recording');
            }

            $audit->record(
                'telehealth.recording_downloaded',
                subject: $recording,
                metadata: ['source' => 'video_service'],
                summary: 'A telehealth recording was downloaded',
            );

            return redirect()->away($link)->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
        }

        $disk = Storage::disk(AttachRecording::DISK);
        abort_unless(is_string($recording->storage_path) && str_starts_with($recording->storage_path, "telehealth/{$session->organization_id}/{$session->id}/") && $disk->exists($recording->storage_path), 404);

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
