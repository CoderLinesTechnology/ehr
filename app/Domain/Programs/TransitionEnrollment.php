<?php

namespace App\Domain\Programs;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Domain\Shared\DomainException;
use App\Models\LevelOfCare;
use App\Models\OrganizationMembership;
use App\Models\Program;
use App\Models\ProgramEnrollment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The one method that changes an open enrollment: it locks the row, checks the actor (programs.enroll, plus
 * programs.view_sud and client visibility for a segmented program), checks EnrollmentStatus's table of moves, stamps
 * the end time of a closing status, writes the insert-only event, audits, and hands the caller's instance back in sync.
 * "History is never overwritten" and "invalid move refused" are structural: the actions below only choose the
 * event type and the inputs.
 *
 * Audit: `program_enrollment.<event type>`, subject the enrollment (never the client's name or any free text; the
 * reason lives only in the insert-only event, which is shown to those who may see the participant).
 */
final class TransitionEnrollment
{
    public const MAX_REASON = 500;

    public function __construct(
        private readonly AccessGuard $guard,
        private readonly AuditLogger $audit,
        private readonly EnrollmentGuard $enrollments,
    ) {}

    /** @throws DomainException */
    public function __invoke(
        ProgramEnrollment $enrollment,
        EnrollmentEventType $type,
        ?EnrollmentStatus $to = null,
        ?LevelOfCare $toLevel = null,
        ?string $reason = null,
        ?OrganizationMembership $authorizedBy = null,
        ?string $relatedEnrollmentId = null,
        bool $reasonRequired = false,
    ): ProgramEnrollment {
        $this->guard->requirePermission('programs.enroll');
        $this->guard->assertInOrganization($enrollment);
        $reason = ProgramText::optional($reason, self::MAX_REASON, 'reason', 'reason');
        if ($reasonRequired && $reason === null) {
            throw new DomainException('Say why: a reason is required.', 'reason_required', 'reason');
        }

        return DB::transaction(function () use ($enrollment, $type, $to, $toLevel, $reason, $authorizedBy, $relatedEnrollmentId) {
            /** @var ProgramEnrollment $locked */
            $locked = ProgramEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $program = Program::query()->findOrFail($locked->program_id);

            $this->enrollments->assertCanEnrollIn($program);
            $this->enrollments->assertCanSee($locked);

            $from = $locked->status;
            $target = $to ?? $from;
            // A request for the status the enrollment already has (a double click, a retried request) changes nothing:
            // no history row, no audit entry.
            if ($to !== null && $to === $from) {
                $enrollment->setRawAttributes($locked->getAttributes(), true);

                return $enrollment;
            }
            if (! $from->isOpen()) {
                throw new DomainException('This enrollment has ended. A returning client is admitted again as a new enrollment.', 'enrollment_closed');
            }
            if ($target !== $from && ! $from->canMoveTo($target)) {
                throw new DomainException("A participant who is “{$from->label()}” cannot be changed to “{$target->label()}”.", 'invalid_transition', 'status');
            }

            $fromLevelId = $locked->current_level_id;
            $toLevelId = $fromLevelId;
            $authorizer = null;
            if ($type === EnrollmentEventType::LevelChanged) {
                $level = $this->enrollments->level($program, $toLevel, 'level_id');
                if ($level === null || $level->id === $fromLevelId) {
                    throw new DomainException('Choose a different level of care.', 'same_level', 'level_id');
                }
                $toLevelId = $level->id;
                $authorizer = $this->enrollments->authorizer($authorizedBy);
            }

            $changes = ['status' => $target, 'current_level_id' => $toLevelId];
            if (! $target->isOpen()) {
                $changes['ended_at'] = now();
            }
            $locked->forceFill($changes)->save();

            EnrollmentHistory::record($locked, $type, $from, $target, $fromLevelId, $toLevelId, $reason, $authorizer?->id, Auth::user(), $relatedEnrollmentId);

            $this->audit->record(
                'program_enrollment.'.$type->value,
                $locked,
                before: ['status' => $from->value],
                after: ['status' => $target->value, 'level_changed' => $toLevelId !== $fromLevelId],
                summary: $program->is_sud_program
                    ? 'Participant enrollment: '.$type->label()
                    : "Participant enrollment in “{$program->name}”: {$type->label()}",
            );

            $enrollment->setRawAttributes($locked->getAttributes(), true);

            return $enrollment;
        });
    }
}
