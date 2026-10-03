<?php

namespace App\Domain\Scheduling\Calendar;

use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\Modality;
use App\Models\Appointment;
use Carbon\CarbonImmutable;

/** One appointment as the calendar screens draw it (no model leaves the read layer). */
final readonly class CalendarEvent
{
    public function __construct(
        public string $id,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $clientName,
        public string $serviceName,
        public string $clinicianId,
        public string $clinicianName,
        public string $locationLabel,
        public Modality $modality,
        public AppointmentStatus $status,
        public string $family,
        public string $zone,
        /** 'appointment' (default) or 'program' (a program group session, read-only on the calendar). */
        public string $kind = 'appointment',
        /** Where the card links to; null = the appointment screen. */
        public ?string $url = null,
    ) {}

    /** @param array<string, string|null> $colors clinician membership id => hex colour */
    public static function from(Appointment $a, string $timezone, array $colors): self
    {
        $local = fn ($instant) => CarbonImmutable::instance($instant)->setTimezone($timezone);

        return new self(
            id: $a->id,
            start: $local($a->starts_at),
            end: $local($a->ends_at),
            clientName: $a->client->displayName(),
            serviceName: $a->service->name,
            clinicianId: $a->clinician_membership_id,
            clinicianName: $a->clinician->professionalName(),
            locationLabel: $a->modality === Modality::Telehealth ? 'Online' : ($a->location?->name ?? '—'),
            modality: $a->modality,
            status: $a->status,
            family: EventFamily::forAppointment($a, $colors[$a->clinician_membership_id] ?? null),
            zone: $timezone,
        );
    }

    public function startMinute(): int
    {
        return $this->start->hour * 60 + $this->start->minute;
    }

    /** Minutes since the START day's midnight (runs past 1440 for an event that crosses midnight). */
    public function endMinute(): int
    {
        return $this->startMinute() + (int) $this->start->diffInMinutes($this->end);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [AppointmentStatus::Cancelled, AppointmentStatus::Rescheduled], true);
    }
}
