<?php

namespace App\Http\Controllers\App\Messages;

use App\Domain\Messaging\ConversationAccess;
use App\Domain\Messaging\ConversationKind;
use App\Domain\Messaging\Directory;
use App\Domain\Messaging\InboxReader;
use App\Domain\Messaging\StartConversation;
use App\Domain\Messaging\ThreadReader;
use App\Domain\Messaging\UnreadCount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\StartConversationRequest;
use App\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The two-panel messages screen: conversation list (+ the open thread). Thin: read services and one action. */
final class ConversationController extends Controller
{
    public function index(Request $request, InboxReader $inbox, Directory $directory): View
    {
        return $this->screen($request, $inbox, $directory, null, null);
    }

    public function show(Request $request, Conversation $conversation, InboxReader $inbox, ThreadReader $threads, Directory $directory): View|JsonResponse
    {
        $member = tenant()->membership();
        $before = is_string($request->query('before')) ? $request->query('before') : null;
        $page = $threads->page($conversation, $member, $before);

        // "Load earlier" without a page reload: the older messages as HTML plus the next cursor.
        if ($request->query('partial') === 'earlier') {
            return response()->json([
                'html' => view('app.messages._messages', ['messages' => $page->messages, 'afterDay' => null])->render(),
                'earlier' => $page->earlier,
            ]);
        }

        return $this->screen($request, $inbox, $directory, $conversation, $page);
    }

    public function store(StartConversationRequest $request, StartConversation $start): RedirectResponse
    {
        $conversation = $start(
            tenant()->membership(),
            $request->kind(),
            (array) $request->validated('participants', []),
            $request->validated('title'),
            $request->client,
        );

        return redirect()->route('app.messages.show', $conversation);
    }

    /** What the open page needs every ten seconds: new messages, read state, unread counts. */
    public function poll(Request $request, Conversation $conversation, ThreadReader $threads): JsonResponse
    {
        $member = tenant()->membership();
        $after = is_string($request->query('after')) ? $request->query('after') : '';
        $messages = $after !== '' ? $threads->after($conversation, $member, $after) : [];
        $summary = UnreadCount::summary($member);
        $cursor = ThreadReader::parse($after);

        return response()->json([
            'html' => $messages === [] ? '' : view('app.messages._messages', [
                'messages' => $messages,
                'afterDay' => $cursor ? fmt()->local(CarbonImmutable::parse($cursor[0]))->format('Y-m-d') : null,
            ])->render(),
            'latest' => $messages === [] ? $after : end($messages)->cursor,
            'incoming' => collect($messages)->contains(fn ($m) => ! $m->mine),
            'readUntil' => $threads->readUntil($conversation, $member),
            'unread' => $summary,
            'listChanged' => (string) $request->query('sig') !== $summary['signature'],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function screen(Request $request, InboxReader $inbox, Directory $directory, ?Conversation $selected, mixed $thread): View
    {
        $member = tenant()->membership();
        $page = $inbox->page(
            $member,
            (string) $request->query('tab', 'all'),
            is_string($request->query('q')) ? $request->query('q') : null,
            is_string($request->query('after')) ? $request->query('after') : null,
            $request->boolean('unread'),
        );

        $data = [
            'inbox' => $page,
            'selectedId' => $selected?->id,
            'thread' => $thread,
            'signature' => UnreadCount::summary($member)['signature'],
            'canTeam' => ConversationAccess::permits($member, ConversationKind::Direct),
            'canClients' => ConversationAccess::permits($member, ConversationKind::Client),
        ];

        // The list on its own (search-as-you-type, refresh after a new message).
        if ($request->query('partial') === 'list') {
            return view('app.messages._list', $data);
        }

        return view('app.messages.index', $data + [
            'staff' => $directory->staff($member),
            'clients' => $directory->clients($member),
        ]);
    }
}
