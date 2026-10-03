<?php

namespace App\Domain\Programs;

use App\Domain\Tenancy\TenantContext;
use App\Models\LevelOfCare;
use App\Models\Program;
use App\Models\ProgramEnrollment;
use App\Models\ProgramSession;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read model of the programs overview (comp 03): the status tabs with their counts, the program cards with
 * participant counts, the Program Overview tiles and the Upcoming Program Schedule. Bounded and constant in size:
 * one grouped query for tab counts, one for the cards (+ their locations), one for the cards' primary levels, one
 * grouped query for every participant figure and two for the schedule: never per card.
 *
 * Participant figures are LIVE only (demo enrollments are never counted) and follow ProgramVisibility::countable:
 * a program flagged as substance-use treatment has no number for someone without `programs.view_sud` (the card
 * says "Restricted"), and its participants are left out of the totals.
 */
final class ProgramBoard
{
    public const PAGE = 12;

    /** Sessions starting within this many days count as "upcoming starts". */
    public const SOON_DAYS = 7;

    public const SCHEDULE_ITEMS = 3;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array{
     *   cards: Paginator,
     *   counts: array<string, int>,
     *   stats: array{programs: int, participants: int, upcoming: int, completed: int},
     *   schedule: list<array{id: string, title: string, start: CarbonImmutable, end: CarbonImmutable, zone: string, place: string, program: string}>,
     * }
     */
    public function __invoke(ProgramFilters $filters): array
    {
        $membership = $this->tenant->membership() ?? abort(404);

        $counts = Program::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();
        $counts = array_merge(array_fill_keys(ProgramStatus::values(), 0), $counts);
        $counts['all'] = array_sum($counts);

        $cards = Program::query()
            ->with('location:id,organization_id,name')
            ->when($filters->status, fn ($q, $s) => $q->where('status', $s->value))
            ->when($filters->q !== '', function ($q) use ($filters) {
                $like = '%'.addcslashes(mb_strtolower($filters->q), '\\%_').'%';

                return $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$like])->orWhereRaw("lower(coalesce(description, '')) like ?", [$like]));
            })
            ->orderBy('created_at')->orderBy('id')
            ->simplePaginate(self::PAGE)->withQueryString();

        $ids = $cards->getCollection()->pluck('id')->all();

        $levels = $ids === [] ? collect() : LevelOfCare::query()
            ->whereIn('program_id', $ids)->where('is_active', true)
            ->orderBy('sort')->orderBy('name')->get(['id', 'organization_id', 'program_id', 'name'])
            ->unique('program_id')->keyBy('program_id');

        // program id => [status => n], live active/completed enrollments the member may know about (segmentation applied):
        // an index-only scan, however long the ended history of the organization is.
        $people = ProgramVisibility::countable(ProgramEnrollment::query(), $membership)
            ->where('record_environment', 'live')->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Completed->value])
            ->selectRaw('program_id, status, count(*) as total')->groupBy('program_id', 'status')->get();
        $byProgram = [];
        $completed = 0;
        foreach ($people as $row) {
            $status = $row->status instanceof EnrollmentStatus ? $row->status->value : (string) $row->status;
            $byProgram[$row->program_id][$status] = (int) $row->total;
            $completed += $status === 'completed' ? (int) $row->total : 0;
        }
        $seesSud = ProgramVisibility::seesSud($membership);

        $cards->getCollection()->transform(function (Program $program) use ($levels, $byProgram, $seesSud) {
            $program->setRelation('primaryLevel', $levels->get($program->id));
            $program->setAttribute('participant_count', ($program->is_sud_program && ! $seesSud) ? null : ($byProgram[$program->id]['active'] ?? 0));

            return $program;
        });

        $now = CarbonImmutable::now();
        $sessions = $this->upcomingSessions()->limit(self::SCHEDULE_ITEMS)->get();
        $soon = $this->upcomingSessions()->where('program_sessions.starts_at', '<', $now->addDays(self::SOON_DAYS))->reorder()->count();

        return [
            'cards' => $cards,
            'counts' => $counts,
            'stats' => [
                'programs' => $counts['upcoming'] + $counts['active'] + $counts['on_hold'],
                'participants' => array_sum(array_map(fn (array $s) => $s['active'] ?? 0, $byProgram)),
                'upcoming' => $soon,
                'completed' => $completed,
            ],
            'schedule' => $sessions->map(fn (ProgramSession $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'start' => CarbonImmutable::instance($s->starts_at)->setTimezone($s->timezone),
                'end' => CarbonImmutable::instance($s->ends_at)->setTimezone($s->timezone),
                'zone' => $s->timezone,
                'place' => $s->placeLabel(),
                'program' => $s->program->name,
            ])->all(),
        ];
    }

    /** @return Builder<ProgramSession> sessions that have not started yet, of open programs, soonest first */
    private function upcomingSessions(): Builder
    {
        return ProgramSession::query()
            ->with(['location:id,organization_id,name', 'program:id,organization_id,name'])
            ->whereNull('cancelled_at')
            ->where('starts_at', '>=', now())
            ->whereIn('program_id', Program::query()->whereIn('status', ['upcoming', 'active'])->select('programs.id'))
            ->orderBy('starts_at')->orderBy('id');
    }
}
