<?php

namespace App\Domain\Programs;

use App\Domain\Audit\AuditLogger;
use App\Domain\Clients\ClientStatus;
use App\Domain\Clients\ClientVisibility;
use App\Domain\Identity\AccessGuard;
use App\Domain\Shared\DomainException;
use App\Models\Client;
use App\Models\LevelOfCare;
use App\Models\Program;
use App\Models\ProgramEnrollment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Admits a client to a program (active from admission) and writes the first event of the enrollment's history.
 *
 *  - Needs `programs.enroll`, plus `programs.view_sud` for a flagged program, and a client the actor may see.
 *  - The program must be upcoming or active; the client active or pending; a program that has active levels of care
 *    needs one chosen.
 *  - The enrollment inherits the client's record environment (a composite foreign key makes any other value
 *    impossible). One OPEN enrollment per client per program is a unique index; losing that race is the same friendly
 *    refusal as the pre-check.
 *
 * Audit: `program_enrollment.admitted` (subject: the enrollment; no client name).
 */
final class AdmitClient
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly AuditLogger $audit,
        private readonly EnrollmentGuard $enrollments,
    ) {}

    /** @throws DomainException */
    public function __invoke(Program $program, Client $client, ?LevelOfCare $level = null, ?string $relatedEnrollmentId = null): ProgramEnrollment
    {
        $this->guard->requirePermission('programs.enroll');
        $this->guard->assertInOrganization($program);
        $this->guard->assertInOrganization($client);

        return DB::transaction(function () use ($program, $client, $level, $relatedEnrollmentId) {
            $locked = Program::query()->whereKey($program->id)->lockForUpdate()->firstOrFail();
            $this->enrollments->assertCanEnrollIn($locked);

            // The client as the database has it now (the caller's instance may be stale or hold only the columns it wrote).
            $client = Client::query()->whereKey($client->id)->firstOrFail();
            if (! ClientVisibility::allows($client, $this->guard->actor())) {
                throw (new ModelNotFoundException)->setModel(Client::class, [$client->getKey()]);
            }
            if (! in_array($locked->status, [ProgramStatus::Upcoming, ProgramStatus::Active], true)) {
                throw new DomainException('Only programs that are upcoming or active admit new participants.', 'program_closed', 'program_id');
            }
            if (! in_array($client->status, [ClientStatus::Active, ClientStatus::Pending], true)) {
                throw new DomainException('Only active or pending clients can be admitted to a program.', 'client_not_eligible', 'client_id');
            }

            $level = $this->enrollments->level($locked, $level);

            if (ProgramEnrollment::query()->where('program_id', $locked->id)->where('client_id', $client->id)->whereIn('status', EnrollmentStatus::OPEN)->exists()) {
                throw $this->alreadyEnrolled();
            }

            $enrollment = new ProgramEnrollment;
            try {
                DB::transaction(function () use ($enrollment, $locked, $client, $level) {
                    $enrollment->forceFill([
                        'record_environment' => $client->record_environment,
                        'client_id' => $client->id,
                        'program_id' => $locked->id,
                        'current_level_id' => $level?->id,
                        'status' => EnrollmentStatus::Active,
                        'admitted_at' => now(),
                    ])->save();
                });
            } catch (QueryException $e) {
                if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'program_enrollments_one_open')) {
                    throw $this->alreadyEnrolled();
                }
                throw $e;
            }

            EnrollmentHistory::record($enrollment, EnrollmentEventType::Admitted, null, EnrollmentStatus::Active, null, $level?->id, null, null, Auth::user(), $relatedEnrollmentId);

            $this->audit->record(
                'program_enrollment.admitted',
                $enrollment,
                after: ['status' => 'active', 'level' => $locked->is_sud_program ? null : $level?->name],
                summary: $locked->is_sud_program ? 'Admitted a participant to a restricted program' : "Admitted a participant to “{$locked->name}”",
            );

            return $enrollment;
        });
    }

    private function alreadyEnrolled(): DomainException
    {
        return new DomainException('This client is already enrolled in this program.', 'already_enrolled', 'client_id');
    }
}
