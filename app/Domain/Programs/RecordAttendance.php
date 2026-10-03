<?php

namespace App\Domain\Programs;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Domain\Shared\DomainException;
use App\Models\Program;
use App\Models\ProgramEnrollment;
use App\Models\ProgramSession;
use App\Models\ProgramSessionAttendance;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records (or corrects) who attended a session. Needs `programs.enroll` (and `programs.view_sud` for a flagged
 * program), a session that has started and was not cancelled, and only participants of THIS program that the actor
 * may see and that are active. An id that is not such a participant is refused, never silently dropped (a crafted
 * request must not half-succeed). Audit: `program_session.attendance_recorded` with counts only.
 */
final class RecordAttendance
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly AuditLogger $audit,
        private readonly EnrollmentGuard $enrollments,
    ) {}

    /**
     * @param  array<string, string>  $statuses  enrollment id => present | absent | excused
     *
     * @throws DomainException
     */
    public function __invoke(ProgramSession $session, array $statuses): int
    {
        $this->guard->requirePermission('programs.enroll');
        $this->guard->assertInOrganization($session);

        $parsed = [];
        foreach ($statuses as $enrollmentId => $status) {
            $value = is_string($status) ? AttendanceStatus::tryFrom($status) : null;
            if (! is_string($enrollmentId) || ! Str::isUuid($enrollmentId) || $value === null) {
                throw new DomainException('One of the attendance entries is not valid.', 'invalid_attendance', 'attendance');
            }
            $parsed[$enrollmentId] = $value;
        }

        return DB::transaction(function () use ($session, $parsed) {
            $locked = ProgramSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $program = Program::query()->findOrFail($locked->program_id);
            $this->enrollments->assertCanEnrollIn($program);

            if ($locked->isCancelled()) {
                throw new DomainException('This session was cancelled.', 'session_cancelled');
            }
            if ($locked->starts_at->isFuture()) {
                throw new DomainException('Attendance can be recorded once the session has started.', 'session_not_started');
            }

            $ids = array_keys($parsed);
            $enrollments = ProgramVisibility::enrollments(ProgramEnrollment::query()->where('program_id', $program->id)->whereIn('id', $ids), $this->guard->actor())
                ->where('status', EnrollmentStatus::Active->value)->get()->keyBy('id');
            if ($enrollments->count() !== count($ids)) {
                throw new DomainException('One of the participants is not available for this session.', 'invalid_attendance', 'attendance');
            }

            $existing = ProgramSessionAttendance::query()->where('session_id', $locked->id)->whereIn('enrollment_id', $ids)->get()->keyBy('enrollment_id');
            $written = 0;
            foreach ($parsed as $enrollmentId => $status) {
                $row = $existing->get($enrollmentId) ?? new ProgramSessionAttendance;
                if ($row->exists && $row->status === $status) {
                    continue;
                }
                $row->forceFill([
                    'program_id' => $program->id, 'session_id' => $locked->id, 'enrollment_id' => $enrollmentId,
                    'record_environment' => $enrollments[$enrollmentId]->record_environment,
                    'status' => $status, 'recorded_by_user_id' => Auth::id(),
                ])->save();
                $written++;
            }

            if ($written > 0) {
                $this->audit->record(
                    'program_session.attendance_recorded',
                    $locked,
                    metadata: ['recorded' => $written, 'of' => count($ids)],
                    summary: "Recorded attendance for the session “{$locked->title}”",
                );
            }

            return $written;
        });
    }
}
