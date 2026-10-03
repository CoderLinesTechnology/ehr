<?php

namespace Tests\Feature\Scheduling\Http;

use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\Modality;
use App\Models\Appointment;
use PHPUnit\Framework\Attributes\Test;

class AppointmentAccessTest extends SchedulingHttpTestCase
{
    private Appointment $apptA;

    private Appointment $apptB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apptA = $this->book($this->drA, $this->clientA, '2026-10-02 09:00:00');
        $this->apptB = $this->book($this->drB, $this->clientB, '2026-10-02 11:00:00');
    }

    #[Test]
    public function a_clinician_sees_only_their_own_appointments_and_view_all_sees_everyone(): void
    {
        $own = $this->as($this->drA)->get($this->url('app.calendar.index', ['date' => '2026-10-02']))->assertOk()->getContent();
        $this->assertStringContainsString('Alice Alpha', $own);
        $this->assertStringNotContainsString('Bruno Bravo', $own);

        $manager = $this->addStaff($this->organization, 'practice_manager');
        $all = $this->as($manager)->get($this->url('app.calendar.index', ['date' => '2026-10-02']))->assertOk()->getContent();
        $this->assertStringContainsString('Alice Alpha', $all);
        $this->assertStringContainsString('Bruno Bravo', $all);

        $this->as($this->drA)->get($this->url('app.appointments.show', ['appointment' => $this->apptA->id]))->assertOk();
        $this->get($this->url('app.appointments.show', ['appointment' => $this->apptB->id]))->assertNotFound();
    }

    #[Test]
    public function each_route_is_guarded_by_its_permission(): void
    {
        $staff = $this->addStaff($this->organization, 'staff');       // view own only
        $billing = $this->addStaff($this->organization, 'billing');   // view all, nothing else
        $receptionist = $this->addStaff($this->organization, 'receptionist');
        $show = $this->url('app.appointments.show', ['appointment' => $this->apptA->id]);

        $this->as($staff)->get($this->url('app.calendar.index'))->assertOk();
        $this->get($this->url('app.appointments.create'))->assertForbidden();
        $this->post($this->url('app.appointments.store'), [])->assertForbidden();
        $this->get($this->url('app.settings.availability.index'))->assertForbidden();

        $this->as($billing)->get($show)->assertOk();
        $this->put($show, ['modality' => 'telehealth'])->assertForbidden();
        $this->post($show.'/transition', ['status' => 'confirmed'])->assertForbidden();
        $this->post($show.'/transition', ['status' => 'cancelled', 'cancellation_kind' => 'practice'])->assertForbidden();
        $this->post($show.'/reschedule', ['date' => '2026-10-05', 'time' => '10:00'])->assertForbidden();

        $this->as($receptionist)->get($this->url('app.appointments.create'))->assertOk();
        $this->get($this->url('app.settings.availability.index'))->assertOk();

        // Cancelling needs its own permission, separate from editing.
        $this->revokeFromRole($this->organization, 'receptionist', 'appointments.cancel');
        $this->post($show.'/transition', ['status' => 'cancelled', 'cancellation_kind' => 'practice'])->assertForbidden();
        $this->post($show.'/transition', ['status' => 'confirmed'])->assertRedirect();
        $this->assertSame(AppointmentStatus::Confirmed, $this->apptA->fresh()->status);

        $this->revokeFromRole($this->organization, 'staff', 'appointments.view');
        $this->as($staff)->get($this->url('app.calendar.index'))->assertForbidden();
    }

    #[Test]
    public function another_organizations_records_are_not_found_or_refused(): void
    {
        $other = $this->url('app.appointments.show', ['appointment' => $this->otherAppointment->id]);
        $this->as($this->adminMembership);

        $this->get($other)->assertNotFound();
        $this->put($other, ['modality' => 'telehealth'])->assertNotFound();
        $this->post($other.'/transition', ['status' => 'confirmed'])->assertNotFound();
        $this->post($other.'/reschedule', ['date' => '2026-10-05', 'time' => '10:00'])->assertNotFound();

        // Booking with another tenant's client, service or clinician is refused and creates nothing.
        $before = Appointment::query()->count();
        $this->post($this->url('app.appointments.store'), [
            'client_id' => $this->otherClient->id, 'service_id' => $this->otherService->id, 'clinician_id' => $this->otherClinician->id,
            'modality' => 'telehealth', 'date' => '2026-10-06', 'time' => '10:00',
        ])->assertSessionHasErrors(['client_id', 'service_id', 'clinician_id']);
        $this->assertSame($before, Appointment::query()->count());

        // Filtering by another tenant's ids matches nothing and leaks nothing.
        $html = $this->get($this->url('app.calendar.index', ['date' => '2026-10-02', 'clinician' => $this->otherClinician->id, 'service' => $this->otherService->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Olga Outsider', $html);
        $this->assertStringNotContainsString('Alice Alpha', $html);

        // The create screen does not resolve their ids either.
        $page = $this->get($this->url('app.appointments.create', ['client' => $this->otherClient->id, 'service' => $this->otherService->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Olga Outsider', $page);
        $this->assertStringNotContainsString('Other Service', $page);
    }

    #[Test]
    public function a_clinician_cannot_book_a_client_they_may_not_see(): void
    {
        $this->as($this->drA)->post($this->url('app.appointments.store'), [
            'client_id' => $this->clientB->id, 'service_id' => $this->therapy->id, 'clinician_id' => $this->drA->id,
            'modality' => 'telehealth', 'date' => '2026-10-06', 'time' => '10:00',
        ])->assertSessionHasErrors('client_id'); // clientB is drB's: no appointment with drA

        $this->post($this->url('app.appointments.store'), [
            'client_id' => $this->clientA->id, 'service_id' => $this->therapy->id, 'clinician_id' => $this->drA->id,
            'modality' => 'telehealth', 'date' => '2026-10-06', 'time' => '10:00',
        ])->assertSessionHasNoErrors()->assertRedirect();
    }

    #[Test]
    public function booking_fixes_the_source_to_staff_and_ignores_a_posted_one(): void
    {
        $this->as($this->adminMembership)->post($this->url('app.appointments.store'), [
            'client_id' => $this->clientA->id, 'service_id' => $this->therapy->id, 'clinician_id' => $this->drA->id,
            'modality' => 'telehealth', 'date' => '2026-10-06', 'time' => '10:00', 'source' => 'portal',
        ])->assertSessionHasNoErrors();

        $booked = Appointment::query()->where('clinician_membership_id', $this->drA->id)->where('starts_at', '2026-10-06 10:00:00+00')->firstOrFail();
        $this->assertSame('staff', $booked->source);
        $this->assertSame($this->actor->id, $booked->booked_by_user_id);

        $this->post($this->url('app.appointments.reschedule', ['appointment' => $booked->id]), ['date' => '2026-10-07', 'time' => '10:00', 'source' => 'public_booking'])
            ->assertSessionHasNoErrors();
        $replacement = Appointment::query()->where('rescheduled_from_id', $booked->id)->firstOrFail();
        $this->assertSame('staff', $replacement->source);
        $this->assertSame(AppointmentStatus::Rescheduled, $booked->fresh()->status);
    }

    #[Test]
    public function the_wall_clock_is_read_in_the_locations_timezone(): void
    {
        $lagos = $this->location('Africa/Lagos');
        $this->as($this->adminMembership)->post($this->url('app.appointments.store'), [
            'client_id' => $this->clientA->id, 'service_id' => $this->therapy->id, 'clinician_id' => $this->drA->id,
            'modality' => 'in_person', 'location_id' => $lagos->id, 'date' => '2026-10-06', 'time' => '10:00',
        ])->assertSessionHasNoErrors();

        $booked = Appointment::query()->where('location_id', $lagos->id)->firstOrFail();
        $this->assertSame('2026-10-06 09:00:00', $booked->starts_at->utc()->format('Y-m-d H:i:s'), 'Lagos is UTC+1');
    }

    #[Test]
    public function only_someone_who_may_overbook_can_double_book(): void
    {
        $payload = ['client_id' => $this->clientB->id, 'service_id' => $this->therapy->id, 'clinician_id' => $this->drA->id,
            'modality' => 'telehealth', 'date' => '2026-10-02', 'time' => '09:30', 'allow_overlap' => '1'];

        $receptionist = $this->addStaff($this->organization, 'receptionist'); // no appointments.overbook
        $ready = ['client' => $this->clientA->id, 'service' => $this->therapy->id, 'clinician' => $this->drA->id, 'modality' => 'telehealth', 'date' => '2026-10-02', 'time' => '09:30'];
        $this->as($receptionist)->get($this->url('app.appointments.create', $ready))->assertOk()->assertSee('Book appointment')->assertDontSee('Book even if it overlaps');
        $this->post($this->url('app.appointments.store'), $payload)->assertSessionHasErrors('time');
        $this->assertSame(1, Appointment::query()->where('clinician_membership_id', $this->drA->id)->count());

        $manager = $this->addStaff($this->organization, 'practice_manager');
        $this->as($manager)->get($this->url('app.appointments.create', $ready))->assertSee('Book even if it overlaps');
        $this->post($this->url('app.appointments.store'), $payload)->assertSessionHasNoErrors();
        $this->assertTrue(Appointment::query()->where('clinician_membership_id', $this->drA->id)->where('allow_overlap', true)->exists());
    }

    #[Test]
    public function status_actions_confirm_cancel_and_report_refusals_on_the_right_field(): void
    {
        $show = $this->url('app.appointments.show', ['appointment' => $this->apptA->id]);
        $this->as($this->adminMembership)->get($show)->assertOk()->assertSee('Confirm')->assertSee('Pending');

        $this->post($show.'/transition', ['status' => 'cancelled'])->assertSessionHasErrors('cancellation_kind');
        $this->assertSame(AppointmentStatus::Scheduled, $this->apptA->fresh()->status);

        $this->post($show.'/transition', ['status' => 'cancelled', 'cancellation_kind' => 'client', 'reason' => 'Unwell'])->assertRedirect($show);
        $fresh = $this->apptA->fresh();
        $this->assertSame(AppointmentStatus::Cancelled, $fresh->status);
        $this->assertSame('Unwell', $fresh->cancellation_reason);

        $this->post($show.'/transition', ['status' => 'confirmed'])->assertSessionHasErrors('status');
        $this->post($show.'/transition', ['status' => 'rescheduled'])->assertSessionHasErrors('status');
        $this->post($show.'/transition', ['status' => 'bogus'])->assertSessionHasErrors('status');
    }

    #[Test]
    public function slots_come_from_availability_and_the_confirm_panel_appears_when_complete(): void
    {
        $this->rule($this->drA, $this->accra, 1, '09:00', '12:00'); // Mondays, in person at Accra
        $params = ['client' => $this->clientA->id, 'service' => $this->therapy->id, 'clinician' => $this->drA->id, 'modality' => 'in_person', 'location' => $this->accra->id, 'date' => '2026-10-05'];

        $html = $this->as($this->adminMembership)->get($this->url('app.appointments.create', $params))->assertOk()->getContent();
        $this->assertStringContainsString('Available times', $html);
        $this->assertStringContainsString('time=09%3A00', $html);
        $this->assertStringNotContainsString('Book appointment', $html, 'no time chosen yet');

        $html = $this->get($this->url('app.appointments.create', $params + ['time' => '09:00']))->assertOk()->getContent();
        $this->assertStringContainsString('Book appointment', $html);
        $this->assertStringContainsString('name="time" value="09:00"', $html);
    }

    #[Test]
    public function the_calendar_costs_the_same_queries_whatever_the_number_of_appointments(): void
    {
        $count = fn () => $this->queryCount(fn () => $this->as($this->adminMembership)->get($this->url('app.calendar.index', ['view' => 'week', 'date' => '2026-10-02']))->assertOk());
        $this->book($this->drA, $this->clientB, '2026-10-02 15:00:00', Modality::InPerson); // same shape (with and without a location) before and after
        $count(); // warm one-off lookups
        $few = $count();

        foreach (range(0, 11) as $i) {
            $client = $this->client();
            $this->book($i % 2 ? $this->drA : $this->drB, $client, '2026-10-0'.(1 + $i % 3).' '.(12 + intdiv($i, 3)).':00:00', $i % 2 ? Modality::InPerson : Modality::Telehealth);
        }
        $many = $count();

        $this->assertSame($few, $many, 'no per-row queries');
        $this->assertLessThanOrEqual(45, $many);
    }
}
