<?php

namespace App\Http\Controllers\App\Messages;

use App\Domain\Messaging\MarkRead;
use App\Domain\Messaging\React;
use App\Domain\Messaging\RetractMessage;
use App\Domain\Messaging\SendMessage;
use App\Domain\Messaging\ThreadReader;
use App\Domain\Messaging\UnreadCount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\SendMessageRequest;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Send, read, react, retract. Each calls one domain action; the page enhances them with fetch and falls back to a redirect. */
final class MessageController extends Controller
{
    public function send(SendMessageRequest $request, Conversation $conversation, SendMessage $send): RedirectResponse|JsonResponse
    {
        $send(tenant()->membership(), $conversation, (string) $request->input('body', ''), $request->file('attachment'));

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : redirect()->route('app.messages.show', $conversation);
    }

    public function read(Request $request, Conversation $conversation, MarkRead $mark): JsonResponse|RedirectResponse
    {
        $member = tenant()->membership();
        $mark($member, $conversation);

        return $request->expectsJson()
            ? response()->json(['unread' => UnreadCount::summary($member)])
            : redirect()->route('app.messages.show', $conversation);
    }

    public function react(Request $request, Conversation $conversation, Message $message, React $react, ThreadReader $threads): JsonResponse|RedirectResponse
    {
        $react(tenant()->membership(), $message, (string) $request->input('emoji', ''));

        return $this->answer($request, $conversation, $message, $threads);
    }

    public function retract(Request $request, Conversation $conversation, Message $message, RetractMessage $retract, ThreadReader $threads): JsonResponse|RedirectResponse
    {
        $retract(tenant()->membership(), $message);

        return $this->answer($request, $conversation, $message, $threads);
    }

    /** The message as it now stands, ready to replace the old one. */
    private function answer(Request $request, Conversation $conversation, Message $message, ThreadReader $threads): JsonResponse|RedirectResponse
    {
        if (! $request->expectsJson()) {
            return redirect()->route('app.messages.show', $conversation);
        }

        $one = $threads->one($conversation, tenant()->membership(), $message);

        return response()->json(['html' => $one === null ? '' : view('app.messages._message', ['m' => $one])->render()]);
    }
}
