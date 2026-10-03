<?php

namespace App\Domain\Scheduling;

use App\Domain\Shared\DomainException;
use App\Models\Appointment;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Input for RescheduleAppointment. Null service/clinician/modality/location/
 * notes keep the original's (a telehealth replacement never has a location).
 * The client and live/demo environment always stay the original's.
 */
final readonly class RescheduleAppointmentData
{
    public CarbonImmutable $startsAt;

    public AppointmentSource $source;

    public function __construct(
        public Appointment $appointment,
        DateTimeInterface $startsAt,
        public ?Service $service = null,
        public ?OrganizationMembership $clinician = null,
        public ?Modality $modality = null,
        public ?Location $location = null,
        AppointmentSource|string $source = AppointmentSource::Staff,
        public bool $allowOverlap = false,
        public ?string $schedulingNotes = null,
        public ?string $reason = null,
        public ?User $actor = null,
    ) {
        $this->startsAt = CarbonImmutable::instance($startsAt)->utc()->startOfMinute();
        $this->source = $source instanceof AppointmentSource
            ? $source
            : (AppointmentSource::tryFrom($source) ?? throw new DomainException('Unknown booking source.', 'invalid_source', 'source'));
    }
}
