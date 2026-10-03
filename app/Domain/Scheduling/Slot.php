<?php

namespace App\Domain\Scheduling;

use Carbon\CarbonImmutable;

/**
 * One bookable start time for one clinician. Instants are UTC; $timezone is the
 * zone the resulting appointment would be displayed in (the location's for an
 * in-person slot, the organization's for telehealth).
 */
final readonly class Slot
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $clinicianMembershipId,
        public ?string $locationId,
        public Modality $modality,
        public string $timezone,
    ) {}

    public function localStart(): CarbonImmutable
    {
        return $this->startsAt->setTimezone($this->timezone);
    }

    public function localEnd(): CarbonImmutable
    {
        return $this->endsAt->setTimezone($this->timezone);
    }

    /** The business date the slot falls on, in its display timezone. */
    public function localDate(): string
    {
        return $this->localStart()->format('Y-m-d');
    }

    /** Stable identity: the same start for the same clinician, place and modality. */
    public function key(): string
    {
        return $this->startsAt->getTimestamp().'|'.$this->clinicianMembershipId.'|'.$this->modality->value.'|'.($this->locationId ?? '');
    }
}
