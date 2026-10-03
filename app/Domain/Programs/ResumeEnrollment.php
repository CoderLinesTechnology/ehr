<?php

namespace App\Domain\Programs;

use App\Models\ProgramEnrollment;

/** Brings a participant who is on hold (or pending) back to active. Needs `programs.enroll`. Event: `resumed`. */
final class ResumeEnrollment
{
    public function __construct(private readonly TransitionEnrollment $transition) {}

    public function __invoke(ProgramEnrollment $enrollment, ?string $reason = null): ProgramEnrollment
    {
        return ($this->transition)($enrollment, EnrollmentEventType::Resumed, EnrollmentStatus::Active, reason: $reason);
    }
}
