<?php

namespace App\Domain\Programs;

use App\Domain\Shared\DomainException;
use App\Models\ProgramEnrollment;

/**
 * Ends an enrollment. Two outcomes: `completed` (the participant finished the program; the reason is optional) and
 * `discharged` (any other ending; the reason is REQUIRED). Needs `programs.enroll`. Events: `completed`, `discharged`.
 */
final class DischargeClient
{
    public function __construct(private readonly TransitionEnrollment $transition) {}

    public function __invoke(ProgramEnrollment $enrollment, string $outcome, ?string $reason = null): ProgramEnrollment
    {
        return match ($outcome) {
            'completed' => ($this->transition)($enrollment, EnrollmentEventType::Completed, EnrollmentStatus::Completed, reason: $reason),
            'discharged' => ($this->transition)($enrollment, EnrollmentEventType::Discharged, EnrollmentStatus::Discharged, reason: $reason, reasonRequired: true),
            default => throw new DomainException('Choose whether the participant completed the program or was discharged.', 'invalid_outcome', 'outcome'),
        };
    }
}
