<?php

namespace App\Domain\Scheduling;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Events\AppointmentScheduled;
use App\Domain\Scheduling\Support\AppointmentHistory;
use App\Domain\Scheduling\Support\AppointmentSnapshot;
use App\Domain\Scheduling\Support\BookingRules;
use App\Domain\Scheduling\Support\Conflicts;
use App\Domain\Scheduling\Support\SchedulingSettings;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Scheduling\Support\Text;
use App\Domain\Scheduling\Support\WallClock;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Appointment;
use App\Models\Location;
use App\Models\Organization;
use App\Support\Formatter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Books an appointment: validates the client, service, clinician and place,
 * snapshots duration, price, currency, timezone and live/demo environment,
 * and refuses clashes.
 *
 * Online sources (portal, public booking, waitlist) must take a slot SlotFinder
 * offers for online booking and can never double-book. Staff may book outside
 * availability; a clash with another appointment or blocked time is refused
 * with a message naming the time, unless they knowingly overbook (allowOverlap,
 * permission checked by the caller) and the organization allows it.
 *
 * The exclusion constraint is the last word: losing a race to another booking
 * surfaces as "slot_taken", never as a database error.
 */
final class ScheduleAppointment
{
    public const MAX_NOTES = 2000;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly SlotFinder $slotFinder,
        private readonly BookingRules $rules,
        private readonly SchedulingSettings $settings,
        private readonly Formatter $format,
    ) {}

    public function __invoke(ScheduleAppointmentData $data): Appointment
    {
        $organization = $this->tenant->organizationOrFail();
        TenantGuard::assertOwned($organization->id, $data->client, $data->service, $data->clinician, $data->location, $data->rescheduledFrom);

        try {
            return DB::transaction(fn () => $this->schedule($organization, $data));
        } catch (QueryException $exception) {
            if (Conflicts::isOverlapViolation($exception)) {
                throw new DomainException('That time was just booked by someone else. Please choose another time.', 'slot_taken', 'starts_at');
            }

            throw $exception;
        }
    }

    private function schedule(Organization $organization, ScheduleAppointmentData $data): Appointment
    {
        $service = $data->service;

        $this->rules->assertClientBookable($data->client);
        $this->rules->assertServiceBookable($service);
        $this->rules->assertClinicianProvides($data->clinician, $service);
        $location = $this->rules->placeFor($service, $data->modality, $data->location);
        $timezone = $this->rules->timezoneFor($location, $organization);
        $notes = Text::optional($data->schedulingNotes, self::MAX_NOTES, 'scheduling_notes', 'scheduling note');

        $startsAt = $data->startsAt;
        $endsAt = $startsAt->addMinutes($service->duration_minutes);

        if ($data->source->isOnline()) {
            if ($data->allowOverlap) {
                throw new DomainException('Online bookings cannot double-book.', 'overlap_not_allowed', 'starts_at');
            }
            $this->assertOnlineSlot($data, $location, $timezone);
            $overlapsAppointment = false;
        } else {
            $overlapsAppointment = $this->checkStaffConflicts($organization, $data, $location, $startsAt, $endsAt, $timezone);
        }

        $appointment = new Appointment;
        $appointment->forceFill([
            'organization_id' => $organization->id,
            'record_environment' => $data->client->record_environment,
            'client_id' => $data->client->id,
            'service_id' => $service->id,
            'clinician_membership_id' => $data->clinician->id,
            'location_id' => $location?->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $timezone,
            'modality' => $data->modality,
            'status' => AppointmentStatus::Scheduled,
            'allow_overlap' => $overlapsAppointment,
            'source' => $data->source->value,
            'price_minor' => $service->price_minor,
            'currency' => $service->currency,
            'scheduling_notes' => $notes,
            'cancellation_kind' => null,
            'cancellation_reason' => null,
            'late_cancellation' => false,
            'rescheduled_from_id' => $data->rescheduledFrom?->id,
            'booked_by_user_id' => $data->actor?->id,
            'confirmed_at' => null,
            'checked_in_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'no_show_at' => null,
        ])->save();

        AppointmentHistory::record($appointment, null, AppointmentStatus::Scheduled, null, $data->actor);

        $this->audit->record(
            'appointment.scheduled',
            subject: $appointment,
            after: AppointmentSnapshot::of($appointment),
            summary: "{$service->name} booked for {$this->format->dateTime($startsAt, $timezone)}",
        );

        // A replacement is announced once, by RescheduleAppointment.
        if ($data->rescheduledFrom === null) {
            AppointmentScheduled::dispatch($appointment, $data->actor?->id);
        }

        return $appointment;
    }

    /** Online bookings take only what SlotFinder offers online (notice, window, published hours, no clashes). */
    private function assertOnlineSlot(ScheduleAppointmentData $data, ?Location $location, string $timezone): void
    {
        // ±1 day covers rules whose own timezone puts the start on another local date.
        $day = WallClock::dayNumber(WallClock::localDate($data->startsAt, $timezone));

        $slots = $this->slotFinder->find(new SlotQuery(
            service: $data->service,
            from: WallClock::dateOf($day - 1),
            to: WallClock::dateOf($day + 1),
            clinician: $data->clinician,
            location: $location,
            modality: $data->modality,
            forOnlineBooking: true,
        ));

        if (! $slots->contains($data->startsAt, $data->clinician->id, $location?->id, $data->modality)) {
            throw new DomainException('That time is not available. Please choose another time.', 'slot_unavailable', 'starts_at');
        }
    }

    /**
     * Refuses a clash with an occupying appointment of the clinician (any
     * location) or applicable blocked time, unless knowingly overbooked where
     * the organization allows it. Messages name the time, never another client.
     *
     * @return bool whether the booking overlaps another appointment (stored as allow_overlap)
     */
    private function checkStaffConflicts(
        Organization $organization,
        ScheduleAppointmentData $data,
        ?Location $location,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        string $timezone,
    ): bool {
        $clinicianId = $data->clinician->id;

        $appointment = Conflicts::appointments([$clinicianId], $startsAt, $endsAt)
            ->orderBy('starts_at')
            ->first(['id', 'starts_at', 'ends_at']);

        $block = Conflicts::blocks([$clinicianId], $startsAt, $endsAt)
            ->where(fn (Builder $query) => $query->whereNull('location_id')
                ->when($location, fn (Builder $query, Location $location) => $query->orWhere('location_id', $location->id)))
            ->orderBy('starts_at')
            ->first(['id', 'kind', 'starts_at', 'ends_at']);

        if ($appointment === null && $block === null) {
            return false;
        }

        $conflict = $appointment !== null
            ? sprintf(
                '%s already has an appointment from %s to %s on %s.',
                $data->clinician->displayName(),
                $this->format->time($appointment->starts_at, $timezone),
                $this->format->time($appointment->ends_at, $timezone),
                $this->format->localDate($appointment->starts_at, $timezone),
            )
            : sprintf(
                'That time overlaps blocked time (%s) from %s to %s.',
                $block->kind->label(),
                $this->format->dateTime($block->starts_at, $timezone),
                $this->format->dateTime($block->ends_at, $timezone),
            );

        if (! $data->allowOverlap) {
            throw new DomainException($conflict, 'schedule_conflict', 'starts_at');
        }

        if (! $this->settings->allowOverbooking($organization)) {
            throw new DomainException($conflict.' Double-booking is turned off for this organization.', 'overbooking_disabled', 'starts_at');
        }

        return $appointment !== null;
    }
}
