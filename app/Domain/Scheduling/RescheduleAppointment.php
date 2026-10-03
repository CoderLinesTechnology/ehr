<?php

namespace App\Domain\Scheduling;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Events\AppointmentRescheduled;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Scheduling\Support\Text;
use App\Domain\Tenancy\TenantContext;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Moves an upcoming appointment: in one transaction the original becomes
 * "rescheduled" (history + audit) and a replacement is booked through
 * ScheduleAppointment with rescheduled_from_id pointing back, so late-cancel
 * and no-show history is never overwritten. Because the original stops
 * occupying time first, moving within its own slot works. If the new booking
 * is refused, nothing changes.
 */
final class RescheduleAppointment
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly TransitionAppointment $transition,
        private readonly ScheduleAppointment $schedule,
    ) {}

    /** @return Appointment the replacement */
    public function __invoke(RescheduleAppointmentData $data): Appointment
    {
        $organization = $this->tenant->organizationOrFail();
        TenantGuard::assertOwned($organization->id, $data->appointment, $data->service, $data->clinician, $data->location);

        $reason = Text::optional($data->reason, TransitionAppointment::MAX_REASON, 'reason', 'reason');

        return DB::transaction(function () use ($data, $reason) {
            /** @var Appointment $original */
            $original = Appointment::query()->lockForUpdate()->findOrFail($data->appointment->id);

            $this->transition->markRescheduled($original, $data->actor, $reason);

            $modality = $data->modality ?? $original->modality;
            $location = $modality === Modality::InPerson
                ? ($data->location ?? ($original->location_id !== null ? Location::query()->findOrFail($original->location_id) : null))
                : null;

            $replacement = ($this->schedule)(new ScheduleAppointmentData(
                client: Client::query()->findOrFail($original->client_id),
                service: $data->service ?? Service::query()->findOrFail($original->service_id),
                clinician: $data->clinician ?? OrganizationMembership::query()->findOrFail($original->clinician_membership_id),
                location: $location,
                modality: $modality,
                startsAt: $data->startsAt,
                source: $data->source,
                allowOverlap: $data->allowOverlap,
                schedulingNotes: $data->schedulingNotes ?? $original->scheduling_notes,
                actor: $data->actor,
                rescheduledFrom: $original,
            ));

            $this->audit->record(
                'appointment.rescheduled',
                subject: $original,
                before: ['starts_at' => $original->starts_at, 'ends_at' => $original->ends_at],
                after: ['replacement_id' => $replacement->id, 'starts_at' => $replacement->starts_at, 'ends_at' => $replacement->ends_at],
                metadata: array_filter(['reason' => $reason]),
                summary: 'Appointment rescheduled',
            );

            AppointmentRescheduled::dispatch($original, $replacement, $reason, $data->actor?->id);

            $data->appointment->setRawAttributes($original->getAttributes(), true);

            return $replacement;
        });
    }
}
