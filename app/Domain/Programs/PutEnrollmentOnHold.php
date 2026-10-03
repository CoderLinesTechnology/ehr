<?php

namespace App\Domain\Programs;

use App\Models\ProgramEnrollment;

/** Pauses an active participant (they stay enrolled and keep their level). Needs `programs.enroll`. Event: `put_on_hold`. */
final class PutEnrollmentOnHold
{
    public function __construct(private readonly TransitionEnrollment $transition) {}

    public function __invoke(ProgramEnrollment $enrollment, ?string $reason = null): ProgramEnrollment
    {
        return ($this->transition)($enrollment, EnrollmentEventType::PutOnHold, EnrollmentStatus::OnHold, reason: $reason);
    }
}
