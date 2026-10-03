<?php

namespace App\Domain\Scheduling;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Support\BookingRules;
use App\Domain\Scheduling\Support\Conflicts;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Scheduling\Support\Text;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Appointment;
use App\Models\Location;
use App\Models\Service;
use App\Support\Formatter;
use Illuminate\Support\Facades\DB;

/**
 * Edits what can change without moving the appointment: the scheduling note,
 * and the place (modality/location) at the same time. A new place is
 * validated like a booking, including blocked time scoped to the new
 * location. Times, clinician, service and status change only through
 * RescheduleAppointment / TransitionAppointment. Closed appointments are final.
 */
final class UpdateAppointmentDetails
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly BookingRules $rules,
        private readonly Formatter $format,
    ) {}

    public function __invoke(Appointment $appointment, AppointmentDetailsData $data): Appointment
    {
        $organization = $this->tenant->organizationOrFail();
        TenantGuard::assertOwned($organization->id, $appointment, $data->location);

        $notes = Text::optional($data->schedulingNotes, ScheduleAppointment::MAX_NOTES, 'scheduling_notes', 'scheduling note');

        return DB::transaction(function () use ($organization, $appointment, $data, $notes) {
            /** @var Appointment $locked */
            $locked = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);

            if ($locked->status->isTerminal()) {
                throw new DomainException('A completed, cancelled, rescheduled or no-show appointment can no longer be edited.', 'appointment_closed');
            }

            $modality = $data->modality ?? $locked->modality;
            $locationId = $modality === Modality::Telehealth ? null : ($data->location?->id ?? $locked->location_id);

            $changes = ['scheduling_notes' => $notes];

            if ($modality !== $locked->modality || $locationId !== $locked->location_id) {
                $service = Service::query()->findOrFail($locked->service_id);
                $location = $modality === Modality::InPerson && $locationId !== null
                    ? ($data->location ?? Location::query()->findOrFail($locationId))
                    : null;
                $location = $this->rules->placeFor($service, $modality, $location);

                if ($location !== null) {
                    $this->assertLocationNotBlocked($locked, $location);
                }

                $changes += [
                    'modality' => $modality,
                    'location_id' => $location?->id,
                    'timezone' => $this->rules->timezoneFor($location, $organization),
                ];
            }

            $locked->forceFill($changes);
            $this->audit->recordChanges('appointment.updated', $locked);
            $locked->save();

            $appointment->setRawAttributes($locked->getAttributes(), true);

            return $appointment;
        });
    }

    /** Blocked time scoped to the new location (e.g. a room closure) now applies. */
    private function assertLocationNotBlocked(Appointment $locked, Location $location): void
    {
        $block = Conflicts::blocks([$locked->clinician_membership_id], $locked->starts_at, $locked->ends_at)
            ->where('location_id', $location->id)
            ->orderBy('starts_at')
            ->first(['id', 'kind', 'starts_at', 'ends_at']);

        if ($block !== null) {
            throw new DomainException(sprintf(
                '%s has blocked time (%s) from %s to %s.',
                $location->name,
                $block->kind->label(),
                $this->format->dateTime($block->starts_at, $location->timezone),
                $this->format->dateTime($block->ends_at, $location->timezone),
            ), 'schedule_conflict', 'location_id');
        }
    }
}
