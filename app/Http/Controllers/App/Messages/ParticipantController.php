<?php

namespace App\Http\Controllers\App\Messages;

use App\Domain\Messaging\AddParticipants;
use App\Domain\Messaging\LeaveGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\AddParticipantsRequest;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;

final class ParticipantController extends Controller
{
    public function store(AddParticipantsRequest $request, Conversation $conversation, AddParticipants $add): RedirectResponse
    {
        $added = $add(tenant()->membership(), $conversation, (array) $request->validated('participants'));

        return redirect()->route('app.messages.show', $conversation)
            ->with('success', $added === 1 ? 'Added 1 person.' : "Added {$added} people.");
    }

    public function leave(Conversation $conversation, LeaveGroup $leave): RedirectResponse
    {
        $leave(tenant()->membership(), $conversation);

        return redirect()->route('app.messages.index')->with('success', 'You left the group.');
    }
}
