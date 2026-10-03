<?php

namespace App\Domain\Scheduling\Listeners;

use App\Domain\Clients\Timeline;
use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\CancellationKind;
use App\Domain\Scheduling\Events\AppointmentRescheduled;
use App\Domain\Scheduling\Events\AppointmentScheduled;
use App\Domain\Scheduling\Events\AppointmentStatusChanged;
use App\Domain\Tenancy\TenantContext;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Service;
use App\Support\Formatter;
use Closure;

/**
 * Projects scheduling events onto the client's timeline (category
 * "scheduling"): service, clinician and local date/time — never clinical
 * content. Everything it shows is loaded explicitly (strict mode), in a fixed
 * number of queries per event.
 */
final class WriteAppointmentTimeline
{
    public function __construct(
        private readonly Timeline $timeline,
        private readonly TenantContext $tenant,
        private readonly Formatter $format,
    ) {}

    public function handleScheduled(AppointmentScheduled $event): void
    {
        $appointment = $event->appointment;

        $this->within($appointment, function () use ($appointment, $event) {
            $names = $this->names([$appointment]);

            $this->write($appointment, 'appointment.scheduled', $this->describe($appointment, $names).' — scheduled', $event->actorUserId);
        });
    }

    public function handleStatusChanged(AppointmentStatusChanged $event): void
    {
        $appointment = $event->appointment;

        $this->within($appointment, function () use ($appointment, $event) {
            $names = $this->names([$appointment]);
            $summary = $this->describe($appointment, $names).' — '.$this->statusPhrase($appointment, $event->to);

            $this->write($appointment, 'appointment.'.$event->to->value, $summary, $event->actorUserId);
        });
    }

    public function handleRescheduled(AppointmentRescheduled $event): void
    {
        [$original, $replacement] = [$event->original, $event->replacement];

        $this->within($replacement, function () use ($original, $replacement, $event) {
            $names = $this->names([$original, $replacement]);
            $sameParticipants = $original->service_id === $replacement->service_id
                && $original->clinician_membership_id === $replacement->clinician_membership_id;

            $summary = $this->describe($original, $names).' — rescheduled to '
                .($sameParticipants ? $this->when($replacement) : $this->describe($replacement, $names));

            $this->write($replacement, 'appointment.rescheduled', $summary, $event->actorUserId);
        });
    }

    /** Listeners run after commit, normally still inside the request's tenant; restore it if not. */
    private function within(Appointment $appointment, Closure $callback): void
    {
        if ($this->tenant->id() === $appointment->organization_id && ! $this->tenant->bypassing()) {
            $callback();

            return;
        }

        $this->tenant->runAs(Organization::query()->findOrFail($appointment->organization_id), $callback);
    }

    /**
     * Service and clinician names for these appointments: two queries, whatever the count.
     *
     * @param  list<Appointment>  $appointments
     * @return array{services: array<string, string>, clinicians: array<string, string>}
     */
    private function names(array $appointments): array
    {
        $serviceIds = array_values(array_unique(array_map(fn (Appointment $a) => $a->service_id, $appointments)));
        $clinicianIds = array_values(array_unique(array_map(fn (Appointment $a) => $a->clinician_membership_id, $appointments)));

        return [
            'services' => Service::query()->whereKey($serviceIds)->pluck('name', 'id')->all(),
            'clinicians' => OrganizationMembership::query()
                ->whereKey($clinicianIds)
                ->join('users', 'users.id', '=', 'organization_memberships.user_id')
                ->pluck('users.name', 'organization_memberships.id')
                ->all(),
        ];
    }

    /** "Individual therapy with Kwame Mensah on 06/10/2026 at 14:00" in the appointment's own timezone. */
    private function describe(Appointment $appointment, array $names): string
    {
        return sprintf(
            '%s with %s on %s',
            $names['services'][$appointment->service_id] ?? 'Appointment',
            $names['clinicians'][$appointment->clinician_membership_id] ?? 'a clinician',
            $this->when($appointment),
        );
    }

    private function when(Appointment $appointment): string
    {
        return $this->format->localDate($appointment->starts_at, $appointment->timezone)
            .' at '.$this->format->time($appointment->starts_at, $appointment->timezone);
    }

    private function statusPhrase(Appointment $appointment, AppointmentStatus $to): string
    {
        if ($to !== AppointmentStatus::Cancelled) {
            return mb_strtolower($to->label());
        }

        return $appointment->cancellation_kind === CancellationKind::Client->value
            ? 'cancelled by the client'.($appointment->late_cancellation ? ' (late cancellation)' : '')
            : 'cancelled by the practice';
    }

    private function write(Appointment $appointment, string $type, string $summary, ?string $actorUserId): void
    {
        $client = Client::query()->findOrFail($appointment->client_id, ['id', 'organization_id', 'record_environment']);

        $this->timeline->record(
            $client,
            Timeline::SCHEDULING,
            $type,
            $summary,
            subject: $appointment,
            actorUserId: $actorUserId,
            metadata: ['status' => $appointment->status->value, 'starts_at' => $appointment->starts_at->utc()->toIso8601String()],
        );
    }
}
