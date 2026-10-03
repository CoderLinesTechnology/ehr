<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Scheduling\AppointmentDetailsData;
use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\Modality;
use App\Domain\Scheduling\UpdateAppointmentDetails;
use App\Domain\Shared\DomainException;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Database\Factories\BlockedTimeFactory;
use PHPUnit\Framework\Attributes\Test;

class UpdateAppointmentDetailsTest extends SchedulingTestCase
{
    private Service $service;

    private OrganizationMembership $clinician;

    private Location $location;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinician = $this->clinician();
        $this->location = $this->location();
        $this->service = $this->service(['duration_minutes' => 60, 'allows_in_person' => true, 'allows_telehealth' => true], [$this->clinician]);
        $this->appointment = Appointment::factory()->for($this->clinician, 'clinician')->for($this->service)->for($this->location)
            ->at(CarbonImmutable::parse('2026-10-06 14:00', 'UTC'))->create(['scheduling_notes' => 'Old note']);
    }

    private function update(AppointmentDetailsData $data): Appointment
    {
        return app(UpdateAppointmentDetails::class)($this->appointment, $data);
    }

    #[Test]
    public function the_scheduling_note_is_updated_and_audited_with_before_and_after(): void
    {
        $this->update(new AppointmentDetailsData(schedulingNotes: ' New note ', actor: $this->actor));

        $this->assertSame('New note', $this->appointment->scheduling_notes);
        $this->assertSame('New note', $this->appointment->fresh()->scheduling_notes);
        $audit = AuditLog::query()->where('action', 'appointment.updated')->sole();
        $this->assertSame(['scheduling_notes' => 'Old note'], $audit->before);
        $this->assertSame(['scheduling_notes' => 'New note'], $audit->after);
    }

    #[Test]
    public function an_unchanged_save_writes_no_audit_entry(): void
    {
        $this->update(new AppointmentDetailsData(schedulingNotes: 'Old note'));

        $this->assertSame(0, AuditLog::query()->where('action', 'appointment.updated')->count());
    }

    #[Test]
    public function switching_to_telehealth_drops_the_location_and_takes_the_organization_timezone(): void
    {
        $this->useOrganizationTimezone('Africa/Lagos');

        $this->update(new AppointmentDetailsData(schedulingNotes: 'Old note', modality: Modality::Telehealth));

        $fresh = $this->appointment->fresh();
        $this->assertSame([Modality::Telehealth, null, 'Africa/Lagos'], [$fresh->modality, $fresh->location_id, $fresh->timezone]);
        $this->assertSame('2026-10-06 14:00', $fresh->starts_at->utc()->format('Y-m-d H:i'), 'The time does not move.');
        $changed = array_keys(AuditLog::query()->where('action', 'appointment.updated')->sole()->after);
        sort($changed);
        $this->assertSame(['location_id', 'modality', 'timezone'], $changed);
    }

    #[Test]
    public function moving_to_another_location_revalidates_it_and_takes_its_timezone(): void
    {
        $newYork = $this->location('America/New_York');

        $this->update(new AppointmentDetailsData(schedulingNotes: 'Old note', location: $newYork));

        $this->assertSame([$newYork->id, 'America/New_York'], [$this->appointment->location_id, $this->appointment->timezone]);

        $closed = $this->location('Africa/Accra', ['is_active' => false]);
        $this->assertRefused('location_inactive', new AppointmentDetailsData(schedulingNotes: 'Old note', location: $closed));

        $this->service->locations()->attach($this->location->id); // now offered at the first location only
        $this->assertRefused('location_not_allowed', new AppointmentDetailsData(schedulingNotes: 'Old note', location: $this->location('Africa/Accra')));
    }

    #[Test]
    public function back_to_in_person_needs_a_location(): void
    {
        $this->update(new AppointmentDetailsData(schedulingNotes: null, modality: Modality::Telehealth));

        $this->assertRefused('location_required', new AppointmentDetailsData(schedulingNotes: null, modality: Modality::InPerson));
    }

    #[Test]
    public function a_location_with_blocked_time_at_that_hour_is_refused(): void
    {
        $annex = $this->location();
        BlockedTimeFactory::new()->between(CarbonImmutable::parse('2026-10-06 13:00', 'UTC'), CarbonImmutable::parse('2026-10-06 18:00', 'UTC'))
            ->create(['location_id' => $annex->id]);

        $this->assertRefused('schedule_conflict', new AppointmentDetailsData(schedulingNotes: 'Old note', location: $annex));
    }

    #[Test]
    public function closed_appointments_cannot_be_edited(): void
    {
        foreach (AppointmentStatus::TERMINAL as $status) {
            $this->appointment->forceFill(['status' => $status])->save();

            $this->assertRefused('appointment_closed', new AppointmentDetailsData(schedulingNotes: 'Changed'));
        }

        $this->assertSame('Old note', $this->appointment->fresh()->scheduling_notes);
    }

    private function assertRefused(string $code, AppointmentDetailsData $data): void
    {
        $before = $this->appointment->fresh()->getAttributes();

        try {
            $this->update($data);
            $this->fail("Expected {$code}.");
        } catch (DomainException $e) {
            $this->assertSame($code, $e->errorCode(), $e->userMessage());
        }

        $this->assertSame($before, $this->appointment->fresh()->getAttributes());
    }

    private function useOrganizationTimezone(string $timezone): void
    {
        $this->organization->forceFill(['timezone' => $timezone])->save();
    }
}
