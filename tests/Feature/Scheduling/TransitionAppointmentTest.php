<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\CancellationKind;
use App\Domain\Scheduling\Events\AppointmentStatusChanged;
use App\Domain\Scheduling\TransitionAppointment;
use App\Domain\Shared\DomainException;
use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\AuditLog;
use App\Models\OrganizationMembership;
use App\Models\Service;
use App\Models\TimelineEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class TransitionAppointmentTest extends SchedulingTestCase
{
    private Service $service;

    private OrganizationMembership $clinician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinician = $this->clinician();
        $this->service = $this->service(['name' => 'Initial assessment', 'duration_minutes' => 60], [$this->clinician]);
    }

    /** An appointment in $status starting at $start (UTC); now is Fri 2 Oct 08:00 UTC. */
    private function appointment(AppointmentStatus $status = AppointmentStatus::Scheduled, string $start = '2026-10-06 14:00'): Appointment
    {
        return Appointment::factory()->for($this->clinician, 'clinician')->for($this->service)
            ->at(CarbonImmutable::parse($start, 'UTC'))->create(['status' => $status]);
    }

    private function transition(Appointment $appointment, AppointmentStatus $to, ?string $reason = null, CancellationKind|string|null $kind = null): Appointment
    {
        return app(TransitionAppointment::class)($appointment, $to, $this->actor, $reason, $kind);
    }

    /** @return iterable<string, array{AppointmentStatus, AppointmentStatus}> */
    public static function allowedTransitions(): iterable
    {
        foreach (AppointmentStatus::cases() as $from) {
            foreach ($from->allowedTransitions() as $to) {
                yield "{$from->value} → {$to->value}" => [$from, $to];
            }
        }
    }

    #[Test]
    #[DataProvider('allowedTransitions')]
    public function every_allowed_transition_stamps_its_milestone_and_writes_history_audit_event_and_timeline(AppointmentStatus $from, AppointmentStatus $to): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 14:10', 'UTC')); // the appointment has started
        $appointment = $this->appointment($from);
        $events = [];
        Event::listen(AppointmentStatusChanged::class, function ($event) use (&$events) {
            $events[] = $event;
        });

        $result = $this->transition($appointment, $to, 'Front desk update', CancellationKind::Practice);

        $this->assertSame($appointment, $result, 'The caller gets its own instance back…');
        $this->assertSame($to, $appointment->status, '…in sync with the database.');
        $fresh = $appointment->fresh();
        $this->assertSame($to, $fresh->status);
        if ($column = $to->timestampColumn()) {
            $this->assertSame('2026-10-06 14:10', $fresh->{$column}->utc()->format('Y-m-d H:i'));
            $this->assertNotNull($appointment->{$column});
        }

        $history = AppointmentStatusHistory::query()->where('appointment_id', $appointment->id)->sole();
        $this->assertSame([$from, $to, 'Front desk update', $this->actor->id], [$history->from_status, $history->to_status, $history->reason, $history->actor_user_id]);

        $audit = AuditLog::query()->where('action', 'appointment.status_changed')->sole();
        $this->assertSame(['status' => $from->value], $audit->before);
        $this->assertSame($to->value, $audit->after['status']);

        $this->assertCount(1, $events);
        $this->assertSame([$from, $to], [$events[0]->from, $events[0]->to]);

        $entry = TimelineEntry::query()->sole();
        $this->assertSame(['scheduling', "appointment.{$to->value}"], [$entry->category, $entry->type]);
        $this->assertStringStartsWith("Initial assessment with {$this->clinician->user->name} on 06/10/2026 at 14:00 — ", $entry->summary);
    }

    /** @return iterable<string, array{AppointmentStatus, AppointmentStatus}> */
    public static function forbiddenTransitions(): iterable
    {
        yield 'completed → cancelled' => [AppointmentStatus::Completed, AppointmentStatus::Cancelled];
        yield 'cancelled → scheduled' => [AppointmentStatus::Cancelled, AppointmentStatus::Scheduled];
        yield 'cancelled → completed' => [AppointmentStatus::Cancelled, AppointmentStatus::Completed];
        yield 'no-show → checked in' => [AppointmentStatus::NoShow, AppointmentStatus::CheckedIn];
        yield 'in progress → no-show' => [AppointmentStatus::InProgress, AppointmentStatus::NoShow];
        yield 'checked in → confirmed' => [AppointmentStatus::CheckedIn, AppointmentStatus::Confirmed];
        yield 'rescheduled → confirmed' => [AppointmentStatus::Rescheduled, AppointmentStatus::Confirmed];
        yield 'confirmed → scheduled' => [AppointmentStatus::Confirmed, AppointmentStatus::Scheduled];
    }

    #[Test]
    #[DataProvider('forbiddenTransitions')]
    public function transitions_the_state_machine_forbids_are_refused_and_change_nothing(AppointmentStatus $from, AppointmentStatus $to): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 15:00', 'UTC'));
        $appointment = $this->appointment($from);

        try {
            $this->transition($appointment, $to, kind: CancellationKind::Client);
            $this->fail('Expected the transition to be refused.');
        } catch (DomainException $e) {
            $this->assertSame('invalid_transition', $e->errorCode());
        }

        $this->assertSame($from, $appointment->fresh()->status);
        $this->assertSame(0, AppointmentStatusHistory::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'appointment.status_changed')->count());
    }

    #[Test]
    public function rescheduling_is_not_a_plain_transition(): void
    {
        $this->expectExceptionObject(new DomainException('Use “Reschedule” to move an appointment to another time.', 'use_reschedule', 'status'));

        $this->transition($this->appointment(), AppointmentStatus::Rescheduled);
    }

    #[Test]
    public function completion_and_no_show_wait_for_the_start_while_check_in_may_be_two_hours_early(): void
    {
        $appointment = $this->appointment(AppointmentStatus::Confirmed, '2026-10-06 14:00');

        foreach ([AppointmentStatus::Completed, AppointmentStatus::NoShow] as $status) {
            $this->travelTo(CarbonImmutable::parse('2026-10-06 13:59', 'UTC'));
            try {
                $this->transition($appointment, $status);
                $this->fail("{$status->value} before the start must be refused.");
            } catch (DomainException $e) {
                $this->assertSame('too_early', $e->errorCode());
            }
        }

        $this->travelTo(CarbonImmutable::parse('2026-10-06 11:59', 'UTC'));
        try {
            $this->transition($appointment, AppointmentStatus::CheckedIn);
            $this->fail('Check-in more than two hours early must be refused.');
        } catch (DomainException $e) {
            $this->assertSame('too_early', $e->errorCode());
            $this->assertSame('An appointment can be marked “Checked in” at most 2 hours before it starts.', $e->userMessage());
        }

        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));
        $this->transition($appointment, AppointmentStatus::CheckedIn);
        $this->transition($appointment, AppointmentStatus::InProgress);
        $this->assertSame(AppointmentStatus::InProgress, $appointment->fresh()->status);

        $this->travelTo(CarbonImmutable::parse('2026-10-06 14:00', 'UTC'));
        $this->transition($appointment, AppointmentStatus::Completed);
        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
        $this->assertSame(3, AppointmentStatusHistory::query()->where('appointment_id', $appointment->id)->count());
    }

    #[Test]
    public function a_cancellation_must_say_who_cancelled(): void
    {
        $appointment = $this->appointment();

        foreach ([null, 'nobody'] as $kind) {
            try {
                $this->transition($appointment, AppointmentStatus::Cancelled, 'Unwell', $kind);
                $this->fail('A cancellation without a valid kind must be refused.');
            } catch (DomainException $e) {
                $this->assertSame(['cancellation_kind_required', 'cancellation_kind'], [$e->errorCode(), $e->field()]);
            }
        }

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
    }

    /** @return iterable<string, array{string, CancellationKind, ?int, bool}> */
    public static function cancellations(): iterable
    {
        // Appointment Tue 6 Oct 14:00 UTC; organization notice: 24 hours.
        yield 'client, 30h before' => ['2026-10-05 08:00', CancellationKind::Client, null, false];
        yield 'client, exactly 24h before' => ['2026-10-05 14:00', CancellationKind::Client, null, false];
        yield 'client, 10h before' => ['2026-10-06 04:00', CancellationKind::Client, null, true];
        yield 'client, after the start' => ['2026-10-06 14:30', CancellationKind::Client, null, true];
        yield 'practice, 10h before' => ['2026-10-06 04:00', CancellationKind::Practice, null, false];
        yield 'client, 10h before, service notice 4h' => ['2026-10-06 04:00', CancellationKind::Client, 4, false];
        yield 'client, 3h before, service notice 4h' => ['2026-10-06 11:00', CancellationKind::Client, 4, true];
    }

    #[Test]
    #[DataProvider('cancellations')]
    public function a_client_cancellation_inside_the_notice_window_is_late(string $now, CancellationKind $kind, ?int $serviceNotice, bool $late): void
    {
        $this->service->forceFill(['cancellation_notice_hours' => $serviceNotice])->save();
        $appointment = $this->appointment(AppointmentStatus::Confirmed, '2026-10-06 14:00');
        $this->travelTo(CarbonImmutable::parse($now, 'UTC'));

        $this->transition($appointment, AppointmentStatus::Cancelled, '  Family emergency  ', $kind->value);

        $fresh = $appointment->fresh();
        $this->assertSame(AppointmentStatus::Cancelled, $fresh->status);
        $this->assertSame($kind->value, $fresh->cancellation_kind);
        $this->assertSame('Family emergency', $fresh->cancellation_reason);
        $this->assertSame($late, $fresh->late_cancellation);
        $this->assertSame($late, $appointment->late_cancellation, 'The caller instance is in sync.');

        $audit = AuditLog::query()->where('action', 'appointment.status_changed')->sole();
        $this->assertSame(['status' => 'cancelled', 'cancellation_kind' => $kind->value, 'late_cancellation' => $late], $audit->after);

        $expected = $kind === CancellationKind::Practice ? 'cancelled by the practice' : 'cancelled by the client'.($late ? ' (late cancellation)' : '');
        $this->assertStringEndsWith("— {$expected}", TimelineEntry::query()->sole()->summary);
    }

    #[Test]
    public function the_organization_notice_setting_is_read_at_call_time(): void
    {
        $this->setting('scheduling.cancellation_notice_hours', 48);
        $appointment = $this->appointment(AppointmentStatus::Scheduled, '2026-10-06 14:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC')); // 30h before

        $this->transition($appointment, AppointmentStatus::Cancelled, kind: CancellationKind::Client);

        $this->assertTrue($appointment->fresh()->late_cancellation);
    }

    #[Test]
    public function repeating_the_current_status_is_a_no_op(): void
    {
        $appointment = $this->appointment(AppointmentStatus::Confirmed);

        $this->transition($appointment, AppointmentStatus::Confirmed);

        $this->assertSame(0, AppointmentStatusHistory::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'appointment.status_changed')->count());
    }

    #[Test]
    public function the_reason_is_limited_to_what_the_history_can_hold(): void
    {
        $this->expectExceptionObject(new DomainException('The reason may not be longer than 500 characters.', 'too_long', 'reason'));

        $this->transition($this->appointment(), AppointmentStatus::Cancelled, str_repeat('x', 501), CancellationKind::Client);
    }

    #[Test]
    public function a_stale_caller_instance_cannot_skip_the_state_machine(): void
    {
        $appointment = $this->appointment(AppointmentStatus::Scheduled);
        $stale = Appointment::query()->findOrFail($appointment->id);
        $this->transition($appointment, AppointmentStatus::Cancelled, kind: CancellationKind::Practice);

        // $stale still says "scheduled"; the locked row says "cancelled".
        $this->expectExceptionObject(new DomainException('An appointment that is “Cancelled” cannot be changed to “Confirmed”.', 'invalid_transition', 'status'));

        $this->transition($stale, AppointmentStatus::Confirmed);
    }
}
