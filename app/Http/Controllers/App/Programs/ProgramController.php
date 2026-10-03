<?php

namespace App\Http\Controllers\App\Programs;

use App\Domain\Programs\ChangeProgramStatus;
use App\Domain\Programs\ProgramBoard;
use App\Domain\Programs\ProgramColor;
use App\Domain\Programs\ProgramDetail;
use App\Domain\Programs\ProgramFilters;
use App\Domain\Programs\ProgramStatus;
use App\Domain\Programs\SaveProgram;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Http\Controllers\App\Scheduling\MapsDomainErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Programs\ChangeStatusRequest;
use App\Http\Requests\Programs\SaveProgramRequest;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Program;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Programs overview, one program (tabs), create/edit and status changes. Thin: the routes authorize
 * (`can:` → ProgramPolicy), the domain actions own the rules, ProgramBoard / ProgramDetail own the reads.
 */
final class ProgramController extends Controller
{
    use MapsDomainErrors;

    public const TABS = ['overview', 'participants', 'staff', 'levels', 'schedule'];

    public function index(Request $request, ProgramBoard $board): View
    {
        $filters = ProgramFilters::from($request->query());

        return view('app.programs.index', $board($filters) + [
            'filters' => $filters,
            'canCreate' => Gate::allows('create', Program::class),
            'canEnroll' => Gate::allows('programs.enroll'),
            'canManage' => Gate::allows('programs.manage') && $this->entitled(FeatureRegistry::LEVELS_OF_CARE),
            'hasCalendar' => Route::has('app.calendar.index') && Gate::any(['appointments.view', 'appointments.view_all']),
            'hasReports' => Route::has('app.reports.index') && Gate::allows('reports.view'),
        ]);
    }

    public function show(Request $request, Program $program, ProgramDetail $detail): View
    {
        $levelsOn = $this->entitled(FeatureRegistry::LEVELS_OF_CARE);
        $sessionsOn = $this->entitled(FeatureRegistry::GROUPS);
        $tab = (string) $request->query('tab', 'overview');
        $tab = in_array($tab, self::TABS, true) && ($tab !== 'levels' || $levelsOn) && ($tab !== 'schedule' || $sessionsOn) ? $tab : 'overview';

        $program->load('location:id,organization_id,name');
        $canParticipants = $detail->canSeeParticipants($program);
        $data = ['program' => $program, 'tab' => $tab, 'levelsOn' => $levelsOn, 'sessionsOn' => $sessionsOn, 'canParticipants' => $canParticipants,
            'canManage' => Gate::allows('manage', $program), 'canUpdate' => Gate::allows('update', $program), 'canAdmit' => Gate::allows('admit', $program)];

        $data += match ($tab) {
            'participants' => ['participants' => $canParticipants ? $detail->participants($program, $this->enum($request->query('show'), ['open', 'ended', 'all'], 'open'), $this->text($request->query('q'))) : null,
                'show' => $this->enum($request->query('show'), ['open', 'ended', 'all'], 'open'), 'q' => $this->text($request->query('q'))],
            'staff' => ['staff' => $detail->staff($program), 'members' => $data['canManage'] ? $this->members() : []],
            'levels' => ['levels' => $program->levels()->get(), 'levelCounts' => $detail->levelCounts($program)],
            'schedule' => ['sessions' => $detail->sessions($program), 'locations' => $data['canManage'] ? $this->locations() : [], 'members' => $data['canManage'] ? $this->members() : []],
            default => ['summary' => $detail->summary($program)],
        };

        return view('app.programs.show', $data + ['transitions' => $program->status->next()]);
    }

    public function create(): View
    {
        return $this->form(null);
    }

    public function store(SaveProgramRequest $request, SaveProgram $save): RedirectResponse
    {
        $program = $this->attempt(fn () => $save($request->programInput()));

        return redirect()->route('app.programs.show', ['program' => $program])
            ->with('success', 'The program was created as upcoming. Activate it when it starts.');
    }

    public function edit(Program $program): View
    {
        return $this->form($program);
    }

    public function update(SaveProgramRequest $request, Program $program, SaveProgram $save): RedirectResponse
    {
        $this->attempt(fn () => $save($request->programInput(), $program));

        return redirect()->route('app.programs.show', ['program' => $program])->with('success', 'The program was saved.');
    }

    public function status(ChangeStatusRequest $request, Program $program, ChangeProgramStatus $change): RedirectResponse
    {
        $this->attempt(fn () => $change($program, ProgramStatus::from($request->validated('status'))));

        return redirect()->route('app.programs.show', ['program' => $program])->with('success', 'The program is now '.mb_strtolower($program->status->label()).'.');
    }

    private function form(?Program $program): View
    {
        return view('app.programs.form', [
            'program' => $program,
            'colors' => ProgramColor::cases(),
            'icons' => ProgramColor::ICONS,
            'locations' => $this->locations(),
            'canFlagSud' => Gate::allows('programs.view_sud'),
        ]);
    }

    /** @return array<string, string> */
    private function locations(): array
    {
        return Location::query()->active()->orderBy('sort')->orderBy('name')->limit(100)->pluck('name', 'id')->all();
    }

    /** @return array<string, string> */
    private function members(): array
    {
        return OrganizationMembership::query()->active()->with('user:id,name')->select(['id', 'organization_id', 'user_id', 'name_prefix'])->limit(200)->get()
            ->sortBy(fn ($m) => mb_strtolower($m->displayName()))->mapWithKeys(fn ($m) => [$m->id => $m->professionalName()])->all();
    }

    private function entitled(string $feature): bool
    {
        return app(EntitlementService::class)->allows(tenant()->organizationOrFail(), $feature);
    }

    /** @param list<string> $allowed */
    private function enum(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim(mb_substr($value, 0, 100)) : '';
    }
}
