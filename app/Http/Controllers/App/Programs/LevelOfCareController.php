<?php

namespace App\Http\Controllers\App\Programs;

use App\Domain\Programs\SaveLevelOfCare;
use App\Http\Controllers\App\Scheduling\MapsDomainErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Programs\SaveLevelRequest;
use App\Models\LevelOfCare;
use App\Models\Program;
use Illuminate\Http\RedirectResponse;

/** Levels of care of one program (`programs.manage`, entitlement `levels_of_care`; both declared on the routes). */
final class LevelOfCareController extends Controller
{
    use MapsDomainErrors;

    public function store(SaveLevelRequest $request, Program $program, SaveLevelOfCare $save): RedirectResponse
    {
        $this->attempt(fn () => $save($program, $request->levelInput()));

        return $this->back($program, 'The level of care was added.');
    }

    public function update(SaveLevelRequest $request, Program $program, LevelOfCare $level, SaveLevelOfCare $save): RedirectResponse
    {
        $this->attempt(fn () => $save($program, $request->levelInput(), $level));

        return $this->back($program, 'The level of care was saved.');
    }

    private function back(Program $program, string $message): RedirectResponse
    {
        return redirect()->route('app.programs.show', ['program' => $program, 'tab' => 'levels'])->with('success', $message);
    }
}
