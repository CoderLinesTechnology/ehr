<?php

namespace App\Domain\Programs;

use App\Domain\Tenancy\TenantContext;
use App\Models\OrganizationMembership;
use App\Models\Program;
use App\Models\ProgramEnrollment;
use App\Models\ProgramSession;
use App\Models\ProgramSessionAttendance;
use App\Models\ProgramStaff;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Read side of one program's screens. Every participant read goes through ProgramVisibility (segmentation and
 * client visibility); a member who may not see a program's participants gets `canSeeParticipants = false` and
 * nothing else about them, not even a count.
 */
final class ProgramDetail
{
    public const PARTICIPANTS_PER_PAGE = 25;

    public function __construct(private readonly TenantContext $tenant) {}

    public function membership(): OrganizationMembership
    {
        return $this->tenant->membership() ?? abort(404);
    }

    public function canSeeParticipants(Program $program): bool
    {
        return ProgramVisibility::seesParticipantsOf($program, $this->membership());
    }

    /** @return array{participants: int, levels: int, staff: int, sessions: int, next: ?ProgramSession} the Overview tab's figures (live participants only) */
    public function summary(Program $program): array
    {
        $see = $this->canSeeParticipants($program);

        return [
            'participants' => $see ? $this->liveActive($program)->count() : 0,
            'levels' => $program->levels()->where('is_active', true)->count(),
            'staff' => ProgramStaff::query()->where('program_id', $program->id)->count(),
            'sessions' => ProgramSession::query()->where('program_id', $program->id)->whereNull('cancelled_at')->where('starts_at', '>=', now())->count(),
            'next' => ProgramSession::query()->with('location:id,organization_id,name')->where('program_id', $program->id)->whereNull('cancelled_at')->where('starts_at', '>=', now())->orderBy('starts_at')->first(),
        ];
    }

    /**
     * @return Paginator<int, ProgramEnrollment>
     */
    public function participants(Program $program, string $status = 'open', string $q = ''): Paginator
    {
        $query = ProgramVisibility::enrollments(ProgramEnrollment::query(), $this->membership())
            ->where('program_enrollments.program_id', $program->id)
            ->with(['client:id,organization_id,client_number,first_name,last_name,preferred_name,record_environment', 'level:id,organization_id,name'])
            ->when($status === 'open', fn ($w) => $w->whereIn('status', EnrollmentStatus::OPEN))
            ->when($status === 'ended', fn ($w) => $w->whereNotIn('status', EnrollmentStatus::OPEN))
            ->when($q !== '', function ($w) use ($q) {
                $like = '%'.addcslashes(mb_strtolower($q), '\\%_').'%';

                return $w->whereIn('client_id', \App\Models\Client::query()->where(fn ($c) => $c
                    ->whereRaw('lower(first_name) like ?', [$like])->orWhereRaw('lower(last_name) like ?', [$like])
                    ->orWhereRaw("lower(coalesce(preferred_name, '')) like ?", [$like]))->select('clients.id'));
            })
            ->orderByRaw("case when status in ('pending','active','on_hold') then 0 else 1 end")
            ->orderByDesc('admitted_at')->orderBy('id');

        return $query->simplePaginate(self::PARTICIPANTS_PER_PAGE)->withQueryString();
    }

    /** @return Collection<int, ProgramStaff> */
    public function staff(Program $program): Collection
    {
        return ProgramStaff::query()->where('program_id', $program->id)
            ->with(['membership:id,organization_id,user_id,name_prefix', 'membership.user:id,name'])
            ->limit(100)->get()
            ->sortBy(fn (ProgramStaff $s) => [array_search($s->role->value, ['director', 'supervisor', 'clinician', 'coordinator', 'other'], true), mb_strtolower($s->membership->displayName())])
            ->values();
    }

    /**
     * Open participants per level (ids → count), when the member may see them.
     *
     * @return array<string, int>
     */
    public function levelCounts(Program $program): array
    {
        if (! $this->canSeeParticipants($program)) {
            return [];
        }

        return $this->liveActive($program, openOnly: true)->whereNotNull('current_level_id')
            ->selectRaw('current_level_id, count(*) as total')->groupBy('current_level_id')->pluck('total', 'current_level_id')
            ->map(fn ($n) => (int) $n)->all();
    }

    /** @return Collection<int, ProgramSession> sessions of the program, upcoming first then recent past */
    public function sessions(Program $program, int $limit = 30): Collection
    {
        $base = fn () => ProgramSession::query()->with(['location:id,organization_id,name', 'facilitator:id,organization_id,user_id,name_prefix', 'facilitator.user:id,name'])
            ->where('program_id', $program->id);

        $upcoming = $base()->where('starts_at', '>=', now())->orderBy('starts_at')->limit($limit)->get();
        $past = $base()->where('starts_at', '<', now())->orderByDesc('starts_at')->limit(10)->get();

        return $upcoming->concat($past);
    }

    /**
     * Attendance sheet of one session: the active participants the member may see, with what was recorded.
     *
     * @return array{rows: Collection<int, ProgramEnrollment>, recorded: array<string, AttendanceStatus>}
     */
    public function attendance(ProgramSession $session): array
    {
        $program = Program::query()->findOrFail($session->program_id);
        if (! $this->canSeeParticipants($program)) {
            return ['rows' => collect(), 'recorded' => []];
        }

        $rows = ProgramVisibility::enrollments(ProgramEnrollment::query(), $this->membership())
            ->where('program_enrollments.program_id', $program->id)->where('status', EnrollmentStatus::Active->value)
            ->with('client:id,organization_id,client_number,first_name,last_name,preferred_name,record_environment')
            ->limit(300)->get()
            ->sortBy(fn (ProgramEnrollment $e) => mb_strtolower($e->client->last_name.' '.$e->client->first_name))->values();

        $recorded = ProgramSessionAttendance::query()->where('session_id', $session->id)->whereIn('enrollment_id', $rows->pluck('id'))
            ->pluck('status', 'enrollment_id')->map(fn ($s) => $s instanceof AttendanceStatus ? $s : AttendanceStatus::from((string) $s))->all();

        return ['rows' => $rows, 'recorded' => $recorded];
    }

    /** @return \Illuminate\Database\Eloquent\Builder<ProgramEnrollment> */
    private function liveActive(Program $program, bool $openOnly = false): \Illuminate\Database\Eloquent\Builder
    {
        return ProgramVisibility::countable(ProgramEnrollment::query(), $this->membership())
            ->where('program_enrollments.program_id', $program->id)->where('record_environment', 'live')
            ->when($openOnly, fn ($q) => $q->whereIn('status', EnrollmentStatus::OPEN), fn ($q) => $q->where('status', 'active'));
    }
}
