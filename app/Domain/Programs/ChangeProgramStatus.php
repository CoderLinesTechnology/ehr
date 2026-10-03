<?php

namespace App\Domain\Programs;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Domain\Shared\DomainException;
use App\Models\Program;
use App\Models\ProgramEnrollment;
use Illuminate\Support\Facades\DB;

/**
 * The one way a program's status changes. Needs `programs.manage` (and `programs.view_sud` for a flagged program).
 * Locks the program, checks ProgramStatus's table of moves, takes a place of the max_programs limit when a closed
 * program re-opens, and refuses to complete or archive a program that still has open enrollments (discharge or
 * transfer them first: a program never silently ends someone's care). A request for the status the program already
 * has is a no-op. Audit: `program.status_changed`.
 */
final class ChangeProgramStatus
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly AuditLogger $audit,
        private readonly ActiveProgramLimit $limit,
    ) {}

    /** @throws DomainException */
    public function __invoke(Program $program, ProgramStatus $to): Program
    {
        $this->guard->requirePermission('programs.manage');
        $this->guard->assertInOrganization($program);

        return DB::transaction(function () use ($program, $to) {
            $locked = Program::query()->whereKey($program->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($locked->is_sud_program) {
                $this->guard->requirePermission('programs.view_sud');
            }

            if ($from !== $to) {
                if (! $from->canMoveTo($to)) {
                    throw new DomainException("A program that is “{$from->label()}” cannot be changed to “{$to->label()}”.", 'invalid_transition', 'status');
                }
                if (! $from->isOpen() && $to->isOpen()) {
                    $this->limit->assertRoomFor($this->guard->organization());
                }
                if (in_array($to, [ProgramStatus::Completed, ProgramStatus::Archived], true)
                    && ProgramEnrollment::query()->where('program_id', $locked->id)->whereIn('status', EnrollmentStatus::OPEN)->exists()) {
                    throw new DomainException('Participants are still enrolled in this program. Discharge or transfer them first.', 'open_enrollments', 'status');
                }

                $locked->forceFill(['status' => $to])->save();
                $this->audit->record(
                    'program.status_changed',
                    $locked,
                    before: ['status' => $from->value],
                    after: ['status' => $to->value],
                    summary: "Program “{$locked->name}”: {$from->label()} → {$to->label()}",
                );
            }

            $program->setRawAttributes($locked->getAttributes(), true);

            return $program;
        });
    }
}
