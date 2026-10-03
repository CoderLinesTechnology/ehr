<?php

namespace App\Http\Controllers\App\Telehealth;

use App\Domain\Telehealth\SaveSessionNotes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Telehealth\SaveNotesRequest;
use App\Models\TelehealthSession;
use Illuminate\Http\RedirectResponse;

final class NotesController extends Controller
{
    public function update(SaveNotesRequest $request, TelehealthSession $session, SaveSessionNotes $save): RedirectResponse
    {
        $version = $save($session, (string) $request->validated('notes'), $request->user());

        return redirect()->route('app.telehealth.show', ['session' => $session])
            ->with('success', $version === null ? 'The notes are already up to date.' : 'The notes were saved.');
    }
}
