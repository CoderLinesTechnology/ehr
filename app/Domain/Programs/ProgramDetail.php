<?php

namespace App\Domain\Programs;

use App\Domain\Clients\ClientSearch;
use App\Domain\Tenancy\TenantContext;
use App\Models\Client;
use App\Models\OrganizationMembership;
use App\Models\Program;
use App\Models\ProgramEnrollment;
use App\Models\ProgramSession;
use App\Models\ProgramSessionAttendance;
use App\Models\ProgramStaff;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read side of one program's screens. Every participant read goes through ProgramVisibility (segmentation and
 * client visibility); a member who may not see a program's participants gets `canSeeParticipants = false` and
 * nothing else about them, not even a count.
 */
final class ProgramDetail
{
    public const PARTICIPANTS_PER_PAGE = 25;

    public const ATTENDANCE_ROWS = 300;

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
            ->when($q !== '', fn ($w) => $w->whereIn('client_id', ClientSearch::apply(Client::query(), $q)->select('clients.id')))
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
     * Attendance sheet of one session: the active participants the member may see (the first ATTENDANCE_ROWS by name),
     * with what was recorded. `truncated` says there are more than the sheet shows.
     *
     * @return array{rows: Collection<int, ProgramEnrollment>, recorded: array<string, AttendanceStatus>, truncated: bool}
     */
    public function attendance(ProgramSession $session): array
    {
        $program = Program::query()->findOrFail($session->program_id);
        if (! $this->canSeeParticipants($program)) {
            return ['rows' => collect(), 'recorded' => [], 'truncated' => false];
        }

        $client = fn (string $column) => Client::query()->select($column)->whereColumn('clients.id', 'program_enrollments.client_id');
        $rows = ProgramVisibility::enrollments(ProgramEnrollment::query(), $this->membership())
            ->where('program_enrollments.program_id', $program->id)->where('status', EnrollmentStatus::Active->value)
            ->with('client:id,organization_id,client_number,first_name,last_name,preferred_name,record_environment')
            ->orderBy($client('last_name'))->orderBy($client('first_name'))->orderBy('program_enrollments.id')
            ->limit(self::ATTENDANCE_ROWS + 1)->get();
        $truncated = $rows->count() > self::ATTENDANCE_ROWS;
        $rows = $rows->take(self::ATTENDANCE_ROWS)->values();

        $recorded = ProgramSessionAttendance::query()->where('session_id', $session->id)->whereIn('enrollment_id', $rows->pluck('id'))
            ->pluck('status', 'enrollment_id')->map(fn ($s) => $s instanceof AttendanceStatus ? $s : AttendanceStatus::from((string) $s))->all();

        return ['rows' => $rows, 'recorded' => $recorded, 'truncated' => $truncated];
    }

    /** @return Builder<ProgramEnrollment> */
    private function liveActive(Program $program, bool $openOnly = false): Builder
    {
        return ProgramVisibility::countable(ProgramEnrollment::query(), $this->membership())
            ->where('program_enrollments.program_id', $program->id)->where('record_environment', 'live')
            ->when($openOnly, fn ($q) => $q->whereIn('status', EnrollmentStatus::OPEN), fn ($q) => $q->where('status', 'active'));
    }

    /**
     * Active members who may admit and transition clients (candidates for "authorized by"): one query for the grants,
     * one for the people.
     *
     * @return array<string, string> membership id => name
     */
    public function authorizers(): array
    {
        $organizationId = $this->membership()->organization_id;
        $ids = DB::table('membership_roles')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'membership_roles.role_id')
            ->where('membership_roles.organization_id', $organizationId)->where('role_permissions.permission_key', 'programs.enroll')
            ->distinct()->pluck('membership_roles.membership_id');

        return OrganizationMembership::query()->active()->whereIn('id', $ids)->with('user:id,name')->select(['id', 'organization_id', 'user_id', 'name_prefix'])->limit(200)->get()
            ->sortBy(fn ($m) => mb_strtolower($m->displayName()))->mapWithKeys(fn ($m) => [$m->id => $m->professionalName()])->all();
    }
}
