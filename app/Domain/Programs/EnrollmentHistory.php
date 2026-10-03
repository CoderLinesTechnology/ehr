<?php

namespace App\Domain\Programs;

use App\Models\ProgramEnrollment;
use App\Models\ProgramEnrollmentEvent;
use App\Models\User;

/** Writes one insert-only enrollment event. Called only by the Programs actions. */
final class EnrollmentHistory
{
    public static function record(
        ProgramEnrollment $enrollment,
        EnrollmentEventType $type,
        ?EnrollmentStatus $from,
        EnrollmentStatus $to,
        ?string $fromLevelId,
        ?string $toLevelId,
        ?string $reason,
        ?string $authorizedByMembershipId,
        ?User $actor,
        ?string $relatedEnrollmentId = null,
    ): ProgramEnrollmentEvent {
        $event = new ProgramEnrollmentEvent;
        $event->forceFill([
            'organization_id' => $enrollment->organization_id,
            'record_environment' => $enrollment->record_environment,
            'enrollment_id' => $enrollment->id,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'from_level_id' => $fromLevelId,
            'to_level_id' => $toLevelId,
            'reason' => $reason,
            'authorized_by_membership_id' => $authorizedByMembershipId,
            'related_enrollment_id' => $relatedEnrollmentId,
            'actor_user_id' => $actor?->id,
            'occurred_at' => now(),
        ])->save();

        return $event;
    }
}
