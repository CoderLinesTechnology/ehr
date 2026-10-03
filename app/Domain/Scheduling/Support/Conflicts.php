<?php

namespace App\Domain\Scheduling\Support;

use App\Domain\Scheduling\AppointmentStatus;
use App\Models\Appointment;
use App\Models\BlockedTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;

/**
 * What takes a clinician's time: occupying appointments (any location) and
 * blocked time (everyone's or theirs; location-scoped blocks only at that
 * location). SlotFinder and the booking actions share these definitions so
 * "free" means the same thing everywhere.
 */
final class Conflicts
{
    /**
     * No appointment lasts longer than this: duration is always the service's,
     * and services are CHECK-limited to 1440 minutes. It turns "ends after
     * $from" into a bounded index range on (organization_id, starts_at).
     */
    public const MAX_APPOINTMENT_MINUTES = 1440;

    /** Occupying appointments of these clinicians overlapping [$from, $to). */
    public static function appointments(array $clinicianIds, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $from = $from->utc();
        $to = $to->utc();

        return Appointment::query()
            ->whereIn('clinician_membership_id', $clinicianIds)
            ->whereIn('status', AppointmentStatus::OCCUPYING)
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->where('starts_at', '>', $from->subMinutes(self::MAX_APPOINTMENT_MINUTES));
    }

    /**
     * Blocked time for everyone or for any of these clinicians overlapping
     * [$from, $to), served by the GiST index on (organization_id,
     * tstzrange(starts_at, ends_at)). Location scoping is left to the caller.
     */
    public static function blocks(array $clinicianIds, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return BlockedTime::query()
            ->whereRaw(
                'tstzrange(starts_at, ends_at) && tstzrange(CAST(? AS timestamptz), CAST(? AS timestamptz))',
                [self::sql($from), self::sql($to)],
            )
            ->where(fn (Builder $query) => $query->whereNull('membership_id')->orWhereIn('membership_id', $clinicianIds));
    }

    /** Whether a block (membership/location, either null = all) applies to a clinician at a location (null = telehealth). */
    public static function blockApplies(?string $blockMembershipId, ?string $blockLocationId, string $clinicianId, ?string $locationId): bool
    {
        return ($blockMembershipId === null || $blockMembershipId === $clinicianId)
            && ($blockLocationId === null || $blockLocationId === $locationId);
    }

    /** The exclusion constraint refused an overlapping booking (a lost race). */
    public static function isOverlapViolation(QueryException $exception): bool
    {
        $state = $exception->errorInfo[0] ?? (string) $exception->getCode();

        return $state === '23P01' && str_contains($exception->getMessage(), 'appointments_no_clinician_overlap');
    }

    /** An unambiguous timestamptz literal (Laravel's default format drops the offset). */
    public static function sql(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d H:i:s.uP');
    }
}
