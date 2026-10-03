<?php

namespace App\Domain\Telehealth;

use App\Domain\Identity\PermissionResolver;
use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Telehealth\Providers\MeetingLinkPolicy;
use App\Domain\Tenancy\TenantContext;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\OrganizationMembership;
use App\Models\SessionNoteVersion;
use App\Models\TelehealthSession;
use Carbon\CarbonImmutable;

/** Loads the session's page data in a fixed number of queries (appointment, client, clinician, service name, and the clinical extras only when allowed). */
final class SessionDetailsReader
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PermissionResolver $permissions,
        private readonly TelehealthSettings $settings,
    ) {}

    public function read(TelehealthSession $session, OrganizationMembership $viewer, bool $clinical): SessionDetails
    {
        $organization = $this->tenant->organizationOrFail();
        $appointment = Appointment::query()->with('service:id,organization_id,name')->findOrFail($session->appointment_id);
        $client = Client::query()->findOrFail($session->client_id, ['id', 'organization_id', 'first_name', 'last_name', 'preferred_name', 'client_number']);
        $clinician = OrganizationMembership::query()->with('user:id,name')->findOrFail($session->clinician_membership_id);

        $notes = null;
        $version = 0;
        $recordings = [];
        $transcripts = [];
        if ($clinical) {
            $note = $session->note()->first();
            if ($note !== null && $note->latest_version > 0) {
                $current = SessionNoteVersion::query()->where('session_note_id', $note->id)->where('version', $note->latest_version)->first();
                $notes = $current?->body;
                $version = $note->latest_version;
            }
            $recordings = $session->recordings()->whereNull('purged_at')->get()->all();
            $transcripts = $session->transcripts()->get()->all();
        }

        $early = min($this->settings->joinEarlyMinutes($organization), TelehealthSettings::MAX_EARLY_MINUTES);
        $url = $session->join_url;

        return new SessionDetails(
            clientName: $client->displayName(),
            clientNumber: $client->formattedNumber(),
            clientId: $client->id,
            serviceId: $appointment->service_id,
            serviceName: $appointment->service->name,
            clinicianId: $clinician->id,
            clinicianName: $clinician->professionalName(),
            startsAt: $appointment->starts_at,
            endsAt: $appointment->ends_at,
            timezone: $appointment->timezone,
            appointmentId: $appointment->id,
            vendor: MeetingLinkPolicy::vendorLabel(is_string($url) ? $url : null),
            providerLabel: $session->provider_key === 'external_link' ? 'External meeting link' : $session->provider_key,
            hasLink: is_string($url) && $url !== '',
            clinical: $clinical,
            notes: $notes,
            noteVersion: $version,
            recordings: $recordings,
            transcripts: $transcripts,
            nextAppointment: $this->next($appointment, $viewer),
            durationMinutes: $session->duration_minutes,
            joinEarlyMinutes: $early,
            joinWindowOpen: $session->status->isOpen() && JoinWindow::isOpen($appointment->starts_at, $appointment->ends_at, $early, now()),
        );
    }

    /** The client's next booked appointment after this one, as far as the viewer may see it. */
    private function next(Appointment $appointment, OrganizationMembership $viewer): ?array
    {
        $query = Appointment::query()
            ->where('client_id', $appointment->client_id)
            ->where('starts_at', '>=', $appointment->ends_at)
            ->whereIn('status', AppointmentStatus::UPCOMING)
            ->orderBy('starts_at');

        if (! $this->permissions->membershipHas($viewer, 'appointments.view_all')) {
            $query->where('clinician_membership_id', $viewer->id);
        }

        $next = $query->first(['id', 'organization_id', 'starts_at', 'timezone']);

        return $next === null ? null : ['id' => $next->id, 'startsAt' => CarbonImmutable::instance($next->starts_at), 'timezone' => $next->timezone];
    }
}
