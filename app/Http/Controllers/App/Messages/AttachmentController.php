<?php

namespace App\Http\Controllers\App\Messages;

use App\Domain\Messaging\AttachmentStore;
use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Serves a private file to a participant of its conversation, and to nobody else (404, never 403). */
final class AttachmentController extends Controller
{
    public function show(MessageAttachment $attachment): StreamedResponse
    {
        $message = $attachment->message()->firstOrFail();
        Gate::authorize('view', $message->conversation()->firstOrFail());   // denies as 404

        abort_if($attachment->purged_at !== null || $message->isRetracted(), 404);

        $disk = Storage::disk(AttachmentStore::DISK);
        abort_unless($disk->exists($attachment->storage_path), 404);

        // Images show inline in the thread; PDFs download (no active content runs in our origin).
        return $disk->response($attachment->storage_path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], str_starts_with($attachment->mime, 'image/') ? 'inline' : 'attachment');
    }
}
