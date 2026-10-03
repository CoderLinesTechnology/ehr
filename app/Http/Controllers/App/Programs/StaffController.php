<?php

namespace App\Http\Controllers\App\Programs;

use App\Domain\Programs\AssignProgramStaff;
use App\Domain\Programs\RemoveProgramStaff;
use App\Domain\Programs\StaffRole;
use App\Http\Controllers\App\Scheduling\MapsDomainErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Programs\AssignStaffRequest;
use App\Models\OrganizationMembership;
use App\Models\Program;
use App\Models\ProgramStaff;
use Illuminate\Http\RedirectResponse;

/** A program's staff (`programs.manage`, declared on the routes). */
final class StaffController extends Controller
{
    use MapsDomainErrors;

    public function store(AssignStaffRequest $request, Program $program, AssignProgramStaff $assign): RedirectResponse
    {
        $membership = OrganizationMembership::query()->findOrFail($request->validated('membership_id'));
        $this->attempt(fn () => $assign($program, $membership, StaffRole::from($request->validated('role'))));

        return redirect()->route('app.programs.show', ['program' => $program, 'tab' => 'staff'])->with('success', 'The team member was added to the program.');
    }

    public function destroy(Program $program, ProgramStaff $staff, RemoveProgramStaff $remove): RedirectResponse
    {
        $this->attempt(fn () => $remove($staff));

        return redirect()->route('app.programs.show', ['program' => $program, 'tab' => 'staff'])->with('success', 'The team member was removed from the program.');
    }
}
