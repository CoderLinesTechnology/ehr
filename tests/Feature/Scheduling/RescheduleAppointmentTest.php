<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Scheduling\AppointmentSource;
use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\Events\AppointmentRescheduled;
use App\Domain\Scheduling\Events\AppointmentScheduled;
use App\Domain\Scheduling\Events\AppointmentStatusChanged;
use App\Domain\Scheduling\Modality;
use App\Domain\Scheduling\RescheduleAppointment;
use App\Domain\Scheduling\RescheduleAppointmentData;
use App\Domain\Scheduling\ScheduleAppointment;
use App\Domain\Scheduling\ScheduleAppointmentData;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\RecordEnvironment;
use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Service;
use App\Models\TimelineEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

class RescheduleAppointmentTest extends SchedulingTestCase
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
        $this->service = $this->service(['name' => 'Individual therapy', 'duration_minutes' => 50, 'price_minor' => 30000], [$this->clinician]);
        $this->client = $this->client();
        foreach (range(1, 5) as $weekday) {
            $this->rule($this->clinician, $this->location, $weekday, '09:00', '17:00');
        }
    }

    private function book(string $start, array $overrides = []): Appointment
    {
        return app(ScheduleAppointment::class)(new ScheduleAppointmentData(...array_merge([
            'client' => $this->client,
            'service' => $this->service,
            'clinician' => $this->clinician,
            'location' => $this->location,
            'modality' => Modality::InPerson,
            'startsAt' => CarbonImmutable::parse($start, 'UTC'),
            'schedulingNotes' => 'Use the side entrance',
            'actor' => $this->actor,
        ], $overrides)));
    }

    private function reschedule(Appointment $appointment, string $start, array $overrides = []): Appointment
    {
        return app(RescheduleAppointment::class)(new RescheduleAppointmentData(...array_merge([
            'appointment' => $appointment,
            'startsAt' => CarbonImmutable::parse($start, 'UTC'),
            'reason' => 'Client asked for a later time',
            'actor' => $this->actor,
        ], $overrides)));
    }

    #[Test]
    public function the_original_becomes_rescheduled_and_links_to_its_replacement(): void
    {
        $original = $this->book('2026-10-06 14:00');
        $this->service->forceFill(['price_minor' => 45000])->save(); // a later catalogue change
        $events = [];
        foreach ([AppointmentScheduled::class, AppointmentStatusChanged::class, AppointmentRescheduled::class] as $event) {
            Event::listen($event, function ($e) use (&$events) {
                $events[] = $e;
            });
        }

        $replacement = $this->reschedule($original, '2026-10-08 10:00');

        $this->assertSame(AppointmentStatus::Rescheduled, $original->status, 'The caller instance is in sync.');
        $this->assertSame(AppointmentStatus::Rescheduled, $original->fresh()->status);

        $replacement = $replacement->fresh();
        $this->assertSame($original->id, $replacement->rescheduled_from_id);
        $this->assertSame(AppointmentStatus::Scheduled, $replacement->status);
        $this->assertSame('2026-10-08 10:00', $replacement->starts_at->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-10-08 10:50', $replacement->ends_at->utc()->format('Y-m-d H:i'));
        $this->assertSame([$this->client->id, RecordEnvironment::Live], [$replacement->client_id, $replacement->record_environment]);
        $this->assertSame('Use the side entrance', $replacement->scheduling_notes);
        $this->assertSame(45000, $replacement->price_minor, 'A new booking snapshots the current price.');
        $this->assertSame($replacement->id, $original->fresh()->rescheduledTo()->value('id'));

        $originalHistory = AppointmentStatusHistory::query()->where('appointment_id', $original->id)->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertSame([[null, 'scheduled'], ['scheduled', 'rescheduled']], $originalHistory->map(fn ($h) => [$h->from_status?->value, $h->to_status->value])->all());
        $this->assertSame('Client asked for a later time', $originalHistory[1]->reason);
        $this->assertSame([null, AppointmentStatus::Scheduled], [
            AppointmentStatusHistory::query()->where('appointment_id', $replacement->id)->sole()->from_status,
            AppointmentStatusHistory::query()->where('appointment_id', $replacement->id)->sole()->to_status,
        ]);

        $this->assertSame(1, AuditLog::query()->where('action', 'appointment.status_changed')->where('subject_id', $original->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'appointment.scheduled')->where('subject_id', $replacement->id)->count());
        $rescheduled = AuditLog::query()->where('action', 'appointment.rescheduled')->sole();
        $this->assertSame([$original->id, $replacement->id], [$rescheduled->subject_id, $rescheduled->after['replacement_id']]);

        // One event, one timeline entry: a move is announced as a move.
        $this->assertCount(1, $events);
        $this->assertInstanceOf(AppointmentRescheduled::class, $events[0]);
        $this->assertSame([$original->id, $replacement->id], [$events[0]->original->id, $events[0]->replacement->id]);
        $entries = TimelineEntry::query()->where('type', 'appointment.rescheduled')->get();
        $this->assertCount(1, $entries);
        $this->assertSame(
            "Individual therapy with {$this->clinician->user->name} on 06/10/2026 at 14:00 — rescheduled to 08/10/2026 at 10:00",
            $entries[0]->summary,
        );
        $this->assertSame($replacement->id, $entries[0]->subject_id);
    }

    #[Test]
    public function moving_fifteen_minutes_later_into_its_own_old_slot_works(): void
    {
        $original = $this->book('2026-10-06 14:00');

        $replacement = $this->reschedule($original, '2026-10-06 14:15');

        $this->assertSame('2026-10-06 14:15', $replacement->starts_at->utc()->format('Y-m-d H:i'));
        $this->assertSame(1, Appointment::query()->occupying()->count());
    }

    #[Test]
    public function an_online_reschedule_into_its_own_old_slot_works_too(): void
    {
        $this->setting('scheduling.min_notice_hours', 24);
        $original = $this->book('2026-10-06 14:00', ['source' => AppointmentSource::Portal]);

        $replacement = $this->reschedule($original, '2026-10-06 14:15', ['source' => AppointmentSource::Portal]);

        $this->assertSame('portal', $replacement->source);
        $this->assertSame(AppointmentStatus::Rescheduled, $original->fresh()->status);
    }

    #[Test]
    public function if_the_new_booking_is_refused_nothing_changes(): void
    {
        $original = $this->book('2026-10-06 14:00');
        $this->book('2026-10-07 10:00', ['client' => $this->client()]); // the target time is taken
        $counts = fn () => [
            Appointment::query()->count(),
            AppointmentStatusHistory::query()->count(),
            AuditLog::query()->count(),
            TimelineEntry::query()->count(),
        ];
        $before = $counts();

        try {
            $this->reschedule($original, '2026-10-07 10:30');
            $this->fail('The clash must refuse the reschedule.');
        } catch (DomainException $e) {
            $this->assertSame('schedule_conflict', $e->errorCode());
        }

        $this->assertSame(AppointmentStatus::Scheduled, $original->fresh()->status);
        $this->assertSame(AppointmentStatus::Scheduled, $original->status, 'The caller instance was not touched.');
        $this->assertSame($before, $counts());
    }

    #[Test]
    public function only_upcoming_appointments_can_be_rescheduled(): void
    {
        foreach ([AppointmentStatus::CheckedIn, AppointmentStatus::Completed, AppointmentStatus::Cancelled, AppointmentStatus::NoShow, AppointmentStatus::Rescheduled] as $i => $status) {
            $appointment = Appointment::factory()->for($this->clinician, 'clinician')->for($this->service)->for($this->location)
                ->at(CarbonImmutable::parse('2026-10-05 09:00', 'UTC')->addHours($i))->create(['status' => $status]);

            try {
                $this->reschedule($appointment, '2026-10-09 10:00');
                $this->fail("A {$status->value} appointment must not be rescheduled.");
            } catch (DomainException $e) {
                $this->assertSame('cannot_reschedule', $e->errorCode());
            }
            $this->assertSame($status, $appointment->fresh()->status);
        }

        $this->assertSame(0, AppointmentStatusHistory::query()->count());
    }

    #[Test]
    public function a_reschedule_may_change_clinician_service_and_modality(): void
    {
        $original = $this->book('2026-10-06 14:00');
        $colleague = $this->clinician();
        $followUp = $this->service(['name' => 'Follow-up', 'duration_minutes' => 30, 'allows_telehealth' => true], [$colleague]);

        $replacement = $this->reschedule($original, '2026-10-09 16:00', [
            'service' => $followUp,
            'clinician' => $colleague,
            'modality' => Modality::Telehealth,
        ]);

        $replacement = $replacement->fresh();
        $this->assertSame([$followUp->id, $colleague->id, Modality::Telehealth, null], [$replacement->service_id, $replacement->clinician_membership_id, $replacement->modality, $replacement->location_id]);
        $this->assertSame('2026-10-09 16:30', $replacement->ends_at->utc()->format('Y-m-d H:i'));
        $this->assertSame(
            "Individual therapy with {$this->clinician->user->name} on 06/10/2026 at 14:00 — rescheduled to Follow-up with {$colleague->user->name} on 09/10/2026 at 16:00",
            TimelineEntry::query()->where('type', 'appointment.rescheduled')->sole()->summary,
        );
    }
}
