<?php

namespace App\Domain\Programs;

use App\Models\LevelOfCare;
use App\Models\OrganizationMembership;
use App\Models\ProgramEnrollment;

/**
 * Moves a participant to another level of care of the same program. A reason is required and so is the team member
 * who authorised it (defaults to the actor); the previous level stays in the insert-only history.
 * Needs `programs.enroll`. Event: `level_changed`.
 */
final class TransitionLevelOfCare
{
    public function __construct(private readonly TransitionEnrollment $transition) {}

    public function __invoke(ProgramEnrollment $enrollment, LevelOfCare $to, string $reason, ?OrganizationMembership $authorizedBy = null): ProgramEnrollment
    {
        return ($this->transition)($enrollment, EnrollmentEventType::LevelChanged, null, $to, $reason, $authorizedBy, reasonRequired: true);
    }
}
