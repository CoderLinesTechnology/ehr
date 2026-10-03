<?php

namespace App\Http\Controllers\App\Programs;

use App\Domain\Programs\CancelProgramSession;
use App\Domain\Programs\ProgramDetail;
use App\Domain\Programs\RecordAttendance;
use App\Domain\Programs\ScheduleProgramSession;
use App\Http\Controllers\App\Scheduling\MapsDomainErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Programs\AttendanceRequest;
use App\Http\Requests\Programs\ScheduleSessionRequest;
use App\Models\Program;
use App\Models\ProgramSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** A program's group sessions and their attendance sheet (routes authorize; domain actions own the rules). */
final class SessionController extends Controller
{
    use MapsDomainErrors;

    public function store(ScheduleSessionRequest $request, Program $program, ScheduleProgramSession $schedule): RedirectResponse
    {
        $this->attempt(fn () => $schedule($program, $request->validated()), ['range' => 'end_time']);

        return redirect()->route('app.programs.show', ['program' => $program, 'tab' => 'schedule'])->with('success', 'The session was added to the schedule.');
    }

    public function show(Program $program, ProgramSession $session, ProgramDetail $detail): View
    {
        $session->load(['location:id,organization_id,name', 'facilitator:id,organization_id,user_id,name_prefix', 'facilitator.user:id,name']);

        return view('app.programs.session', [
            'program' => $program,
            'session' => $session,
            'sheet' => $detail->attendance($session),
            'canParticipants' => $detail->canSeeParticipants($program),
            'canRecord' => Gate::allows('admit', $program) && ! $session->isCancelled() && $session->starts_at->isPast(),
            'canManage' => Gate::allows('manage', $program),
        ]);
    }

    public function attendance(AttendanceRequest $request, Program $program, ProgramSession $session, RecordAttendance $record): RedirectResponse
    {
        $written = $this->attempt(fn () => $record($session, $request->validated('attendance')));

        return redirect()->route('app.programs.sessions.show', ['program' => $program, 'session' => $session])
            ->with('success', $written > 0 ? 'Attendance was saved.' : 'Nothing changed.');
    }

    public function cancel(Program $program, ProgramSession $session, CancelProgramSession $cancel): RedirectResponse
    {
        $this->attempt(fn () => $cancel($session));

        return redirect()->route('app.programs.show', ['program' => $program, 'tab' => 'schedule'])->with('success', 'The session was cancelled.');
    }
}
