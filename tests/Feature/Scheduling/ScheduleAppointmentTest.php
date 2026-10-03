<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Clients\ClientStatus;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Scheduling\AppointmentSource;
use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\BlockedTimeKind;
use App\Domain\Scheduling\Events\AppointmentScheduled;
use App\Domain\Scheduling\Modality;
use App\Domain\Scheduling\ScheduleAppointment;
use App\Domain\Scheduling\ScheduleAppointmentData;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\RecordEnvironment;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantMismatch;
use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Service;
use App\Models\TimelineEntry;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\BlockedTimeFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ScheduleAppointmentTest extends SchedulingTestCase
{
    private Client $client;

    private Service $service;

    private OrganizationMembership $clinician;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinician = $this->clinician();
        $this->location = $this->location();
        $this->service = $this->service(['name' => 'Individual therapy', 'duration_minutes' => 50, 'price_minor' => 30000, 'currency' => 'GHS'], [$this->clinician]);
        $this->client = $this->client(['first_name' => 'Abena', 'last_name' => 'Owusu']);
        // Published hours: Monday–Friday 09:00–17:00 at the location.
        foreach (range(1, 5) as $weekday) {
            $this->rule($this->clinician, $this->location, $weekday, '09:00', '17:00');
        }
    }

    private function book(string $start = '2026-10-06 14:00', array $overrides = []): Appointment
    {
        return app(ScheduleAppointment::class)(new ScheduleAppointmentData(...array_merge([
            'client' => $this->client,
            'service' => $this->service,
            'clinician' => $this->clinician,
            'location' => $this->location,
            'modality' => Modality::InPerson,
            'startsAt' => CarbonImmutable::parse($start, 'UTC'),
            'actor' => $this->actor,
        ], $overrides)));
    }

    private function assertRefused(string $code, Closure $attempt): DomainException
    {
        $appointments = Appointment::query()->count();

        try {
            $attempt();
        } catch (DomainException $e) {
            $this->assertSame($code, $e->errorCode(), $e->userMessage());
            $this->assertSame($appointments, Appointment::query()->count(), 'Nothing was booked.');

            return $e;
        }

        $this->fail("Expected the booking to be refused with {$code}.");
    }

    #[Test]
    public function a_staff_booking_snapshots_the_booking_and_records_history_audit_event_and_timeline(): void
    {
        $events = [];
        Event::listen(AppointmentScheduled::class, function (AppointmentScheduled $event) use (&$events) {
            $events[] = $event;
        });

        $appointment = $this->book('2026-10-06 14:00', ['schedulingNotes' => '  Prefers the ground-floor room  ']);

        $fresh = Appointment::query()->findOrFail($appointment->id);
        $this->assertSame(AppointmentStatus::Scheduled, $fresh->status);
        $this->assertSame('2026-10-06 14:00:00', $fresh->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-06 14:50:00', $fresh->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('Africa/Accra', $fresh->timezone);
        $this->assertSame([30000, 'GHS'], [$fresh->price_minor, $fresh->currency]);
        $this->assertSame(RecordEnvironment::Live, $fresh->record_environment);
        $this->assertSame([$this->client->id, $this->service->id, $this->clinician->id, $this->location->id],
            [$fresh->client_id, $fresh->service_id, $fresh->clinician_membership_id, $fresh->location_id]);
        $this->assertSame(Modality::InPerson, $fresh->modality);
        $this->assertSame(AppointmentSource::Staff->value, $fresh->source);
        $this->assertFalse($fresh->allow_overlap);
        $this->assertSame($this->actor->id, $fresh->booked_by_user_id);
        $this->assertSame('Prefers the ground-floor room', $fresh->scheduling_notes);

        // The price is a snapshot: changing the catalogue does not touch the booking.
        $this->service->forceFill(['price_minor' => 99900])->save();
        $this->assertSame(30000, $fresh->fresh()->price_minor);

        $history = AppointmentStatusHistory::query()->where('appointment_id', $appointment->id)->get();
        $this->assertCount(1, $history);
        $this->assertNull($history[0]->from_status);
        $this->assertSame(AppointmentStatus::Scheduled, $history[0]->to_status);
        $this->assertSame($this->actor->id, $history[0]->actor_user_id);

        $audit = AuditLog::query()->where('action', 'appointment.scheduled')->sole();
        $this->assertSame([$this->organization->id, 'appointment', $appointment->id], [$audit->organization_id, $audit->subject_type, $audit->subject_id]);
        $this->assertSame('scheduled', $audit->after['status']);
        $this->assertStringNotContainsString('Abena', (string) $audit->summary);

        $this->assertCount(1, $events);
        $this->assertSame($appointment->id, $events[0]->appointment->id);
        $this->assertSame($this->actor->id, $events[0]->actorUserId);

        $entry = TimelineEntry::query()->where('client_id', $this->client->id)->sole();
        $this->assertSame(['scheduling', 'appointment.scheduled'], [$entry->category, $entry->type]);
        $this->assertSame("Individual therapy with {$this->clinician->user->name} on 06/10/2026 at 14:00 — scheduled", $entry->summary);
    }

    #[Test]
    public function staff_may_book_outside_published_availability(): void
    {
        // Saturday evening: no rule covers it.
        $appointment = $this->book('2026-10-10 19:00');

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->status);
    }

    #[Test]
    public function telehealth_has_no_location_and_takes_the_organization_timezone(): void
    {
        $this->useOrganization('America/Chicago');
        $clinician = $this->clinician();
        $service = $this->service(['duration_minutes' => 60, 'allows_telehealth' => true], [$clinician]);
        $client = $this->client();
        $someLocation = $this->location('America/New_York');

        $appointment = app(ScheduleAppointment::class)(new ScheduleAppointmentData(
            client: $client, service: $service, clinician: $clinician, location: $someLocation,
            modality: Modality::Telehealth, startsAt: CarbonImmutable::parse('2026-10-06 15:00', 'UTC'),
        ));

        $this->assertNull($appointment->location_id);
        $this->assertSame('America/Chicago', $appointment->timezone);
    }

    #[Test]
    public function an_in_person_booking_takes_the_location_timezone(): void
    {
        $newYork = $this->location('America/New_York');

        $appointment = $this->book('2026-10-06 14:00', ['location' => $newYork]);

        $this->assertSame('America/New_York', $appointment->timezone);
        $this->assertSame('10:00', $appointment->localStart()->format('H:i'));
    }

    #[Test]
    public function a_demo_client_gets_a_demo_appointment_and_demo_history(): void
    {
        $demo = Client::factory()->demo()->create();

        $appointment = $this->book('2026-10-06 14:00', ['client' => $demo]);

        $this->assertSame(RecordEnvironment::Demo, $appointment->fresh()->record_environment);
        $this->assertSame(RecordEnvironment::Demo, AppointmentStatusHistory::query()->where('appointment_id', $appointment->id)->sole()->record_environment);
        $this->assertSame(RecordEnvironment::Demo, TimelineEntry::query()->where('client_id', $demo->id)->sole()->record_environment);
    }

    /** @return iterable<string, array{string, Closure}> */
    public static function invalidBookings(): iterable
    {
        yield 'archived client' => ['client_archived', function (self $t) {
            $t->client->forceFill(['status' => ClientStatus::Archived, 'archived_at' => now()])->save();
        }];
        yield 'inactive service' => ['service_inactive', fn (self $t) => $t->service->forceFill(['is_active' => false])->save()];
        yield 'suspended clinician' => ['clinician_unavailable', fn (self $t) => $t->clinician->forceFill(['status' => MembershipStatus::Suspended])->save()];
        yield 'clinician who is not a provider' => ['clinician_unavailable', fn (self $t) => $t->clinician->forceFill(['is_provider' => false])->save()];
        yield 'clinician who does not provide the service' => ['service_not_provided', fn (self $t) => $t->service->providers()->detach($t->clinician->id)];
        yield 'modality the service does not offer' => ['modality_not_allowed', fn (self $t) => $t->service->forceFill(['allows_in_person' => false, 'allows_telehealth' => true])->save()];
        yield 'in person without a location' => ['location_required', fn (self $t) => ['location' => null]];
        yield 'closed location' => ['location_inactive', fn (self $t) => $t->location->forceFill(['is_active' => false])->save()];
        yield 'location the service is not offered at' => ['location_not_allowed', fn (self $t) => $t->service->locations()->attach($t->location('Africa/Accra')->id)];
        yield 'scheduling note too long' => ['too_long', fn (self $t) => ['schedulingNotes' => str_repeat('x', 2001)]];
        yield 'unknown source' => ['invalid_source', fn (self $t) => ['source' => 'fax']];
    }

    #[Test]
    #[DataProvider('invalidBookings')]
    public function invalid_bookings_are_refused_with_a_specific_reason(string $code, Closure $arrange): void
    {
        $overrides = $arrange($this);

        $this->assertRefused($code, fn () => $this->book('2026-10-06 14:00', is_array($overrides) ? $overrides : []));
        $this->assertSame(0, AppointmentStatusHistory::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'appointment.scheduled')->count());
    }

    #[Test]
    public function staff_cannot_book_over_another_appointment_and_the_message_names_the_time_not_the_client(): void
    {
        $other = $this->client(['first_name' => 'Yaw', 'last_name' => 'Boateng']);
        $this->book('2026-10-06 14:00', ['client' => $other]);

        $e = $this->assertRefused('schedule_conflict', fn () => $this->book('2026-10-06 14:30'));

        $this->assertSame("{$this->clinician->user->name} already has an appointment from 14:00 to 14:50 on 06/10/2026.", $e->userMessage());
        $this->assertStringNotContainsString('Yaw', $e->userMessage());
        $this->assertStringNotContainsString('Boateng', $e->userMessage());
        $this->assertSame('starts_at', $e->field());
    }

    #[Test]
    public function the_overlap_check_spans_every_location_and_back_to_back_is_fine(): void
    {
        $this->book('2026-10-06 14:00');
        $elsewhere = $this->location();

        $this->assertRefused('schedule_conflict', fn () => $this->book('2026-10-06 14:40', ['location' => $elsewhere]));

        // [14:00, 14:50) and [14:50, 15:40) touch but do not overlap.
        $this->assertSame(AppointmentStatus::Scheduled, $this->book('2026-10-06 14:50')->status);
    }

    #[Test]
    public function staff_cannot_book_into_blocked_time_that_applies(): void
    {
        $at = fn (string $s, string $e) => [CarbonImmutable::parse($s, 'UTC'), CarbonImmutable::parse($e, 'UTC')];
        BlockedTimeFactory::new()->between(...$at('2026-10-06 13:00', '2026-10-06 15:00'))
            ->create(['membership_id' => $this->clinician->id, 'kind' => BlockedTimeKind::Leave]);

        $e = $this->assertRefused('schedule_conflict', fn () => $this->book('2026-10-06 14:00'));
        $this->assertSame('That time overlaps blocked time (Leave) from 06/10/2026 13:00 to 06/10/2026 15:00.', $e->userMessage());

        // A block at another location does not apply here; one at this location does.
        $elsewhere = $this->location();
        BlockedTimeFactory::new()->between(...$at('2026-10-07 13:00', '2026-10-07 15:00'))->create(['location_id' => $elsewhere->id]);
        $this->assertSame(AppointmentStatus::Scheduled, $this->book('2026-10-07 14:00')->status);

        BlockedTimeFactory::new()->between(...$at('2026-10-08 13:00', '2026-10-08 15:00'))->create(['location_id' => $this->location->id]);
        $this->assertRefused('schedule_conflict', fn () => $this->book('2026-10-08 14:00'));
    }

    #[Test]
    public function knowingly_overbooking_needs_allow_overlap_and_the_organization_setting(): void
    {
        $this->book('2026-10-06 14:00');

        $this->setting('scheduling.allow_overbooking', false);
        $e = $this->assertRefused('overbooking_disabled', fn () => $this->book('2026-10-06 14:30', ['allowOverlap' => true]));
        $this->assertStringContainsString('Double-booking is turned off', $e->userMessage());

        $this->setting('scheduling.allow_overbooking', true);
        $overbooked = $this->book('2026-10-06 14:30', ['allowOverlap' => true]);

        $this->assertTrue($overbooked->fresh()->allow_overlap);
        $this->assertSame(2, Appointment::query()->occupying()->count());
    }

    #[Test]
    public function allow_overlap_without_an_actual_clash_is_an_ordinary_booking(): void
    {
        $appointment = $this->book('2026-10-06 14:00', ['allowOverlap' => true]);

        $this->assertFalse($appointment->fresh()->allow_overlap);
    }

    #[Test]
    public function online_bookings_must_take_an_offered_slot(): void
    {
        $this->setting('scheduling.min_notice_hours', 24);

        $booked = $this->book('2026-10-06 14:00', ['source' => AppointmentSource::Portal]);
        $this->assertSame('portal', $booked->source);

        $this->assertRefused('slot_unavailable', fn () => $this->book('2026-10-06 14:00', ['source' => 'public_booking'])); // taken
        $this->assertRefused('slot_unavailable', fn () => $this->book('2026-10-06 14:05', ['source' => 'public_booking'])); // off the grid
        $this->assertRefused('slot_unavailable', fn () => $this->book('2026-10-10 10:00', ['source' => 'public_booking'])); // Saturday: no hours
        $this->assertRefused('slot_unavailable', fn () => $this->book('2026-10-02 15:00', ['source' => 'waitlist'])); // inside minimum notice
        $this->assertRefused('overlap_not_allowed', fn () => $this->book('2026-10-07 10:00', ['source' => 'portal', 'allowOverlap' => true]));
    }

    #[Test]
    public function the_database_refuses_an_overlapping_occupying_appointment(): void
    {
        $this->book('2026-10-06 14:00');

        try {
            Appointment::factory()->for($this->clinician, 'clinician')->for($this->service)->for($this->location)
                ->at(CarbonImmutable::parse('2026-10-06 14:30', 'UTC'))->create();
            $this->fail('The exclusion constraint should have refused the row.');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->errorInfo[0]);
            $this->assertStringContainsString('appointments_no_clinician_overlap', $e->getMessage());
        }
    }

    #[Test]
    public function losing_the_race_to_another_booking_is_reported_as_slot_taken_and_leaves_nothing_behind(): void
    {
        // Another request commits an overlapping booking after our checks but before our insert.
        $raced = false;
        Event::listen('eloquent.creating: '.Appointment::class, function () use (&$raced) {
            if ($raced) {
                return;
            }
            $raced = true;
            Appointment::factory()->for($this->clinician, 'clinician')->for($this->service)->for($this->location)
                ->at(CarbonImmutable::parse('2026-10-06 14:20', 'UTC'))->create();
        });

        $e = $this->assertRefused('slot_taken', fn () => $this->book('2026-10-06 14:00'));

        $this->assertSame('That time was just booked by someone else. Please choose another time.', $e->userMessage());
        $this->assertSame('starts_at', $e->field());
        $this->assertSame(0, AppointmentStatusHistory::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'appointment.scheduled')->count());
        $this->assertSame(0, TimelineEntry::query()->count());
    }

    /** @return iterable<string, array{string}> */
    public static function foreignRecords(): iterable
    {
        yield 'client' => ['client'];
        yield 'service' => ['service'];
        yield 'clinician' => ['clinician'];
        yield 'location' => ['location'];
    }

    #[Test]
    #[DataProvider('foreignRecords')]
    public function a_record_from_another_organization_is_refused_and_nothing_is_written(string $which): void
    {
        $home = $this->organization;
        $elsewhere = $this->createOrganization()->organization;
        $foreign = $this->inTenant($elsewhere, function () use ($elsewhere) {
            $clinician = $this->addStaff($elsewhere);

            return [
                'client' => Client::factory()->create(),
                'service' => $this->service([], [$clinician]),
                'clinician' => $clinician,
                'location' => Location::factory()->create(),
            ];
        });

        try {
            $this->book('2026-10-06 14:00', [$which => $foreign[$which]]);
            $this->fail('A cross-tenant booking must be refused.');
        } catch (TenantMismatch) {
            // expected
        }

        $this->assertSame(0, Appointment::acrossTenants()->count());
        $this->assertSame(0, AppointmentStatusHistory::acrossTenants()->count());
        $this->assertSame($home->id, app(TenantContext::class)->id());
    }

    #[Test]
    public function the_composite_foreign_keys_refuse_a_cross_tenant_appointment_even_when_the_application_is_bypassed(): void
    {
        $elsewhere = $this->createOrganization()->organization;
        $foreignService = $this->inTenant($elsewhere, fn () => Service::factory()->create());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key/');

        Appointment::factory()->for($this->clinician, 'clinician')->for($this->location)
            ->at(CarbonImmutable::parse('2026-10-06 14:00', 'UTC'))
            ->create(['service_id' => $foreignService->id, 'price_minor' => 1, 'currency' => 'GHS', 'ends_at' => CarbonImmutable::parse('2026-10-06 15:00', 'UTC')]);
    }
}
