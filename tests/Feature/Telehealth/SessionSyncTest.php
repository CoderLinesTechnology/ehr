<?php

namespace Tests\Feature\Telehealth;

use App\Domain\Scheduling\AppointmentSource;
use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\CancellationKind;
use App\Domain\Scheduling\Modality;
use App\Domain\Scheduling\RescheduleAppointment;
use App\Domain\Scheduling\RescheduleAppointmentData;
use App\Domain\Scheduling\TransitionAppointment;
use App\Domain\Shared\RecordEnvironment;
use App\Domain\Telehealth\EndSession;
use App\Domain\Telehealth\EnsureTelehealthSession;
use App\Domain\Telehealth\OpenSession;
use App\Domain\Telehealth\PrepareRoom;
use App\Domain\Telehealth\SessionStatus;
use App\Domain\Telehealth\SyncTelehealthSession;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\TelehealthSession;
use App\Models\TelehealthSessionStatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/** The scheduling events create, move and retire telehealth sessions. */
class SessionSyncTest extends TelehealthTestCase
{
    #[Test]
    public function booking_a_telehealth_appointment_creates_exactly_one_scheduled_session_with_history(): void
    {
        $appointment = $this->book($this->drA, $this->clientA, '2026-10-06 10:00:00', Modality::Telehealth);

        $session = $this->sessionOf($appointment);
        $this->assertSame(SessionStatus::Scheduled, $session->status);
        $this->assertSame($appointment->client_id, $session->client_id);
        $this->assertSame($this->drA->id, $session->clinician_membership_id);
        $this->assertSame('daily', $session->provider_key);
        $this->assertNull($session->join_url);
        $this->assertNull($session->provider_room_name);
        $this->assertFalse($session->consent_to_record);
        $this->assertSame([], $this->daily->calls, 'booking makes no outbound call: the room is created when the join page opens');
        $this->assertSame(1, TelehealthSessionStatusHistory::query()->where('telehealth_session_id', $session->id)->count());

        // Replaying the ensure step (a re-delivered event) creates nothing new.
        app(EnsureTelehealthSession::class)($appointment);
        $this->assertSame(1, TelehealthSession::query()->count());
    }

    #[Test]
    public function an_in_person_appointment_has_no_session(): void
    {
        $this->book($this->drA, $this->clientA, '2026-10-06 10:00:00', Modality::InPerson);

        $this->assertSame(0, TelehealthSession::query()->count());
    }

    #[Test]
    public function the_session_follows_its_appointment_through_cancel_no_show_and_completion(): void
    {
        $cancelled = $this->book($this->drA, $this->clientA, '2026-10-06 10:00:00', Modality::Telehealth);
        app(TransitionAppointment::class)($cancelled, AppointmentStatus::Cancelled, $this->actor, null, CancellationKind::Practice);
        $this->assertSame(SessionStatus::Cancelled, $this->sessionOf($cancelled)->status);

        $this->travelTo(CarbonImmutable::parse('2026-10-06 11:30:00', 'UTC'));
        $missed = $this->book($this->drB, $this->clientB, '2026-10-06 11:00:00', Modality::Telehealth);
        app(TransitionAppointment::class)($missed, AppointmentStatus::NoShow, $this->actor);
        $this->assertSame(SessionStatus::Missed, $this->sessionOf($missed)->status);

        $done = $this->book($this->drA, $this->clientB, '2026-10-06 11:00:00', Modality::Telehealth);
        app(TransitionAppointment::class)($done, AppointmentStatus::Completed, $this->actor);
        $session = $this->sessionOf($done);
        $this->assertSame(SessionStatus::Completed, $session->status);
        $this->assertNotNull($session->ended_at);
        $this->assertSame(60, $session->duration_minutes, 'a session completed without being joined takes the appointment length');
    }

    #[Test]
    public function rescheduling_moves_the_video_room_to_the_new_session_so_the_clients_link_keeps_working(): void
    {
        $old = $this->sessionAt('2026-10-06 10:00:00');
        [$room, $link] = [$old->provider_room_name, $old->join_url];
        $this->assertNotNull($room);

        $replacement = app(RescheduleAppointment::class)(new RescheduleAppointmentData(
            appointment: Appointment::query()->findOrFail($old->appointment_id), startsAt: CarbonImmutable::parse('2026-10-07 14:00:00', 'UTC'), clinician: $this->drA,
            source: AppointmentSource::Staff, actor: $this->actor,
        ));

        $old->refresh();
        $this->assertSame(SessionStatus::Cancelled, $old->status);
        $this->assertNull($old->provider_room_name, 'the old session let go of the room');
        $this->assertNull($old->join_url);
        $new = $this->sessionOf($replacement);
        $this->assertSame(SessionStatus::Scheduled, $new->status);
        $this->assertSame($room, $new->provider_room_name);
        $this->assertSame($link, $new->join_url);
        $this->assertSame(2, TelehealthSession::query()->count());
        $this->assertNotContains('rooms.delete', $this->dailyOperations(), 'cancelling the old session must not delete the moved room');

        // The new window reaches Daily the next time the room is prepared.
        app(PrepareRoom::class)($new);
        $update = $this->daily->callsOf('rooms.update')[0] ?? null;
        $this->assertNotNull($update);
        $this->assertSame($room, $update['name']);
        $this->assertSame(CarbonImmutable::parse('2026-10-07 13:45:00', 'UTC')->getTimestamp(), $update['body']['properties']['nbf']);
        $this->assertSame(CarbonImmutable::parse('2026-10-07 17:00:00', 'UTC')->getTimestamp(), $update['body']['properties']['exp']);
    }

    #[Test]
    public function a_late_event_never_drags_a_finished_session_backwards(): void
    {
        $session = $this->sessionAt('2026-10-06 10:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-06 10:05:00', 'UTC'));
        app(OpenSession::class)($session, $this->actor);
        app(EndSession::class)($session, $this->actor);

        // The appointment is completed too; syncing again changes nothing and adds no history.
        $before = TelehealthSessionStatusHistory::query()->count();
        app(SyncTelehealthSession::class)(Appointment::query()->findOrFail($session->appointment_id), null, $this->actor);

        $this->assertSame(SessionStatus::Completed, $session->refresh()->status);
        $this->assertSame($before, TelehealthSessionStatusHistory::query()->count());
    }

    #[Test]
    public function status_history_and_note_versions_are_insert_only_in_the_database(): void
    {
        $session = $this->sessionAt('2026-10-06 10:00:00');

        foreach ([
            fn () => DB::table('telehealth_session_status_histories')->update(['reason' => 'x']),
            fn () => DB::table('telehealth_session_status_histories')->delete(),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('A status history row was changed.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('insert-only', $e->getMessage());
            }
        }

        $this->assertNotNull($session->statusHistory()->first());
    }

    #[Test]
    public function a_demo_clients_session_is_demo_and_the_database_refuses_a_live_session_for_a_demo_appointment(): void
    {
        $demoClient = Client::factory()->demo()->create(['first_name' => 'Dee', 'last_name' => 'Demo']);
        $appointment = $this->book($this->drA, $demoClient, '2026-10-06 10:00:00', Modality::Telehealth);

        $session = $this->sessionOf($appointment);
        $this->assertSame(RecordEnvironment::Demo, $session->record_environment);

        try {
            DB::transaction(fn () => DB::table('telehealth_sessions')->where('id', $session->id)->update(['record_environment' => 'live']));
            $this->fail('A demo session was turned into live data.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('violates foreign key constraint', $e->getMessage());
        }
    }
}
