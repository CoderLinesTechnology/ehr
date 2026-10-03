<?php

namespace App\Domain\Scheduling;

use App\Domain\Shared\DomainException;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Input for ScheduleAppointment. $startsAt is an instant (any timezone; stored
 * as UTC, truncated to the minute). $allowOverlap asks to knowingly double-book;
 * the caller must have checked the appointments.overbook permission.
 * $rescheduledFrom is set by RescheduleAppointment only.
 */
final readonly class ScheduleAppointmentData
{
    public CarbonImmutable $startsAt;

    public AppointmentSource $source;

    public function __construct(
        public Client $client,
        public Service $service,
        public OrganizationMembership $clinician,
        public Modality $modality,
        DateTimeInterface $startsAt,
        public ?Location $location = null,
        AppointmentSource|string $source = AppointmentSource::Staff,
        public bool $allowOverlap = false,
        public ?string $schedulingNotes = null,
        public ?User $actor = null,
        public ?Appointment $rescheduledFrom = null,
    ) {
        $this->startsAt = CarbonImmutable::instance($startsAt)->utc()->startOfMinute();
        $this->source = $source instanceof AppointmentSource
            ? $source
            : (AppointmentSource::tryFrom($source) ?? throw new DomainException('Unknown booking source.', 'invalid_source', 'source'));
    }
}
