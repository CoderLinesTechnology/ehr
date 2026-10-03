<?php

namespace App\Http\Controllers\App\Programs;

use App\Domain\Clients\ClientStatus;
use App\Domain\Clients\ClientVisibility;
use App\Domain\Programs\AdmitClient;
use App\Domain\Programs\DischargeClient;
use App\Domain\Programs\EnrollmentStatus;
use App\Domain\Programs\ProgramStatus;
use App\Domain\Programs\PutEnrollmentOnHold;
use App\Domain\Programs\ResumeEnrollment;
use App\Domain\Programs\TransferClient;
use App\Domain\Programs\TransitionLevelOfCare;
use App\Http\Controllers\App\Scheduling\MapsDomainErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Programs\AdmitRequest;
use App\Http\Requests\Programs\EnrollmentReasonRequest;
use App\Http\Requests\Programs\LevelChangeRequest;
use App\Models\Client;
use App\Models\LevelOfCare;
use App\Models\OrganizationMembership;
use App\Models\Program;
use App\Models\ProgramEnrollment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Admitting clients and moving a participant through their program (level, hold, discharge, transfer). The routes
 * authorize (`can:` → ProgramPolicy / ProgramEnrollmentPolicy); the domain actions apply the rules again.
 */
final class EnrollmentController extends Controller
{
    use MapsDomainErrors;

    /** Admit form: pick a program, then a client the member may see and (if the program has them) a level. */
    public function create(Request $request): View
    {
        $program = null;
        $id = $request->query('program');
        if (is_string($id) && Str::isUuid($id)) {
            $program = Program::query()->whereKey($id)->first();
            if ($program !== null && Gate::denies('admit', $program)) {
                $program = null;
            }
        }

        $programs = Program::query()->whereIn('status', [ProgramStatus::Upcoming->value, ProgramStatus::Active->value])->orderBy('name')->limit(100)->get()
            ->filter(fn (Program $p) => Gate::allows('admit', $p))->values();

        $q = is_string($request->query('q')) ? trim(mb_substr($request->query('q'), 0, 100)) : '';
        $clients = collect();
        if ($program !== null) {
            $membership = tenant()->membership();
            $like = '%'.addcslashes(mb_strtolower($q), '\\%_').'%';
            $clients = ClientVisibility::apply(Client::query(), $membership)
                ->whereIn('status', [ClientStatus::Active->value, ClientStatus::Pending->value])
                ->whereNotIn('clients.id', ProgramEnrollment::query()->where('program_id', $program->id)->whereIn('status', EnrollmentStatus::OPEN)->select('client_id'))
                ->when($q !== '', fn ($c) => $c->where(fn ($w) => $w->whereRaw('lower(first_name) like ?', [$like])->orWhereRaw('lower(last_name) like ?', [$like])
                    ->orWhereRaw("lower(coalesce(preferred_name, '')) like ?", [$like])))
                ->select(['id', 'organization_id', 'client_number', 'first_name', 'last_name', 'preferred_name', 'record_environment'])
                ->orderBy('last_name')->orderBy('first_name')->limit(20)->get();
        }

        return view('app.programs.admit', [
            'program' => $program,
            'programs' => $programs,
            'clients' => $clients,
            'levels' => $program !== null ? LevelOfCare::query()->where('program_id', $program->id)->where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'organization_id', 'program_id', 'name']) : collect(),
            'q' => $q,
        ]);
    }

    public function store(AdmitRequest $request, AdmitClient $admit): RedirectResponse
    {
        $program = Program::query()->findOrFail($request->validated('program_id'));
        Gate::authorize('admit', $program);
        $client = Client::query()->findOrFail($request->validated('client_id'));
        $level = filled($request->validated('level_id')) ? LevelOfCare::query()->findOrFail($request->validated('level_id')) : null;

        $this->attempt(fn () => $admit($program, $client, $level), ['level_id' => 'level_id']);

        return redirect()->route('app.programs.show', ['program' => $program, 'tab' => 'participants'])->with('success', 'The client was admitted to the program.');
    }

    public function show(Program $program, ProgramEnrollment $enrollment): View
    {
        $enrollment->load(['client:id,organization_id,client_number,first_name,last_name,preferred_name,record_environment', 'level:id,organization_id,name',
            'events.fromLevel:id,organization_id,name', 'events.toLevel:id,organization_id,name', 'events.authorizedBy.user:id,name', 'events.actor:id,name']);
        $open = $enrollment->status->isOpen();
        $canManage = $open && Gate::allows('manage', $enrollment);

        return view('app.programs.enrollment', [
            'program' => $program,
            'enrollment' => $enrollment,
            'canManage' => $canManage,
            'levels' => $canManage ? LevelOfCare::query()->where('program_id', $program->id)->where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'organization_id', 'program_id', 'name']) : collect(),
            'staff' => $canManage ? OrganizationMembership::query()->active()->with('user:id,name')->select(['id', 'organization_id', 'user_id', 'name_prefix'])->limit(200)->get()
                ->filter(fn ($m) => app(\App\Domain\Identity\PermissionResolver::class)->membershipHas($m, 'programs.enroll'))->mapWithKeys(fn ($m) => [$m->id => $m->professionalName()])->all() : [],
            'targets' => $canManage ? Program::query()->whereIn('status', [ProgramStatus::Upcoming->value, ProgramStatus::Active->value])->whereKeyNot($program->id)->orderBy('name')->limit(100)->get()
                ->filter(fn (Program $p) => Gate::allows('admit', $p))->values() : collect(),
        ]);
    }

    public function level(LevelChangeRequest $request, Program $program, ProgramEnrollment $enrollment, TransitionLevelOfCare $transition): RedirectResponse
    {
        $level = LevelOfCare::query()->findOrFail($request->validated('level_id'));
        $by = filled($request->validated('authorized_by')) ? OrganizationMembership::query()->findOrFail($request->validated('authorized_by')) : null;
        $this->attempt(fn () => $transition($enrollment, $level, (string) $request->validated('reason'), $by));

        return $this->back($program, $enrollment, 'The level of care was changed.');
    }

    public function hold(EnrollmentReasonRequest $request, Program $program, ProgramEnrollment $enrollment, PutEnrollmentOnHold $hold): RedirectResponse
    {
        $this->attempt(fn () => $hold($enrollment, $request->validated('reason')));

        return $this->back($program, $enrollment, 'The participant is on hold.');
    }

    public function resume(EnrollmentReasonRequest $request, Program $program, ProgramEnrollment $enrollment, ResumeEnrollment $resume): RedirectResponse
    {
        $this->attempt(fn () => $resume($enrollment, $request->validated('reason')));

        return $this->back($program, $enrollment, 'The participant is active again.');
    }

    public function discharge(EnrollmentReasonRequest $request, Program $program, ProgramEnrollment $enrollment, DischargeClient $discharge): RedirectResponse
    {
        $this->attempt(fn () => $discharge($enrollment, (string) $request->validated('outcome'), $request->validated('reason')));

        return $this->back($program, $enrollment, 'The enrollment has ended.');
    }

    public function transfer(EnrollmentReasonRequest $request, Program $program, ProgramEnrollment $enrollment, TransferClient $transfer): RedirectResponse
    {
        $target = Program::query()->findOrFail($request->validated('program_id'));
        $level = filled($request->validated('level_id')) ? LevelOfCare::query()->findOrFail($request->validated('level_id')) : null;
        $new = $this->attempt(fn () => $transfer($enrollment, $target, $level, $request->validated('reason')));

        return redirect()->route('app.programs.enrollments.show', ['program' => $target, 'enrollment' => $new])->with('success', 'The participant was transferred.');
    }

    private function back(Program $program, ProgramEnrollment $enrollment, string $message): RedirectResponse
    {
        return redirect()->route('app.programs.enrollments.show', ['program' => $program, 'enrollment' => $enrollment])->with('success', $message);
    }
}
