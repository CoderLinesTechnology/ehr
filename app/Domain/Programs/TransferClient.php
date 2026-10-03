<?php

namespace App\Domain\Programs;

use App\Domain\Identity\AccessGuard;
use App\Domain\Shared\DomainException;
use App\Models\Client;
use App\Models\LevelOfCare;
use App\Models\Program;
use App\Models\ProgramEnrollment;
use Illuminate\Support\Facades\DB;

/**
 * Moves a participant to another program: the current enrollment ends as `transferred` (event `transferred`, with
 * the reason and a link to the new enrollment) and the client is admitted to the target in the same transaction
 * (event `admitted`, linked back). Both programs' rules apply (permissions, segmentation, open programs, levels);
 * any refusal rolls both back. Returns the NEW enrollment.
 */
final class TransferClient
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly AdmitClient $admit,
        private readonly TransitionEnrollment $transition,
    ) {}

    /** @throws DomainException */
    public function __invoke(ProgramEnrollment $enrollment, Program $target, ?LevelOfCare $level = null, ?string $reason = null): ProgramEnrollment
    {
        $this->guard->requirePermission('programs.enroll');
        $this->guard->assertInOrganization($enrollment);
        $this->guard->assertInOrganization($target);

        if ($enrollment->program_id === $target->id) {
            throw new DomainException('Choose a different program to transfer to.', 'same_program', 'program_id');
        }

        return DB::transaction(function () use ($enrollment, $target, $level, $reason) {
            $client = Client::query()->findOrFail($enrollment->client_id);
            $new = ($this->admit)($target, $client, $level, $enrollment->id);
            ($this->transition)($enrollment, EnrollmentEventType::Transferred, EnrollmentStatus::Transferred, reason: $reason, relatedEnrollmentId: $new->id);

            return $new;
        });
    }
}
