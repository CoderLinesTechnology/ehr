<?php

namespace Tests\Feature\Telehealth;

use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\CancellationKind;
use App\Domain\Scheduling\Modality;
use App\Domain\Scheduling\TransitionAppointment;
use App\Domain\Shared\DomainException;
use App\Domain\Telehealth\IssueCallPass;
use App\Domain\Telehealth\OpenSession;
use App\Domain\Telehealth\PrepareRoom;
use App\Domain\Telehealth\ReconcileSessions;
use App\Domain\Telehealth\RecordConsent;
use App\Domain\Telehealth\SessionStatus;
use App\Domain\Tenancy\TenantMismatch;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\TelehealthSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/** Rooms (one per session, prepared lazily, released when the session finishes) and passes (who may own, who may record). */
class VideoRoomTest extends TelehealthTestCase
{
    // ── preparing rooms ──────────────────────────────────────────────────────

    #[Test]
    public function a_room_is_prepared_once_and_reused_while_its_window_is_right(): void
    {
        $session = $this->sessionAt('2026-10-02 08:30:00');
        $room = $session->provider_room_name;

        $again = app(PrepareRoom::class)($session);

        $this->assertSame($room, $again?->name);
        $this->assertSame(['rooms.create'], $this->dailyOperations());
        $create = $this->daily->callsOf('rooms.create')[0]['body'];
        $this->assertSame('private', $create['privacy']);
        $this->assertTrue($create['properties']['enable_knocking']);
        $this->assertArrayNotHasKey('enable_recording', $create['properties']);
        $this->assertFalse($create['properties']['enable_chat']);
    }

    #[Test]
    public function demo_sessions_never_reach_daily_and_say_so(): void
    {
        $demo = Client::factory()->demo()->create(['first_name' => 'Dee', 'last_name' => 'Demo']);
        $session = $this->sessionAt('2026-10-02 08:05:00', $this->drA, $demo);

        $this->assertNull($session->provider_room_name);
        $this->as($this->drA)->get($this->joinUrl($session))->assertOk()
            ->assertSee('Demo session — video is not connected')->assertDontSee('class="tj-join-form"', false)->assertDontSee('Copy Meeting Link');
        $this->post($this->url('app.telehealth.start', ['session' => $session->id]))->assertSessionHas('error');
        $this->assertSame(SessionStatus::Scheduled, $session->refresh()->status);

        // A demo session made "in progress" from the calendar still has no call.
        app(TransitionAppointment::class)(Appointment::query()->findOrFail($session->appointment_id), AppointmentStatus::InProgress, $this->actor);
        $this->get($this->callUrl($session))->assertOk()->assertSee('Demo session — video is not connected')->assertDontSee('<iframe', false);

        $this->assertSame([], $this->daily->calls, 'no outbound call for demo data');

        // And with the real client configured, still nothing leaves.
        $this->realDaily();
        Http::fake();
        $this->get($this->joinUrl($session))->assertOk();
        $this->get($this->callUrl($session))->assertOk();
        Http::assertNothingSent();
    }

    #[Test]
    public function an_installation_without_video_set_up_makes_no_call_and_tells_admins_what_to_configure(): void
    {
        $this->videoNotConfigured();
        $session = $this->sessionAt('2026-10-02 08:05:00');
        $this->assertNull($session->provider_room_name);

        $this->as($this->drA)->get($this->joinUrl($session))->assertOk()
            ->assertSee('Video calls are not set up yet')->assertSee('Ask your administrator')->assertDontSee('DAILY_API_KEY')
            ->assertDontSee('class="tj-join-form"', false);
        $this->post($this->url('app.telehealth.start', ['session' => $session->id]))->assertSessionHas('error');
        $this->assertSame(SessionStatus::Scheduled, $session->refresh()->status);

        $admin = $this->addStaff($this->organization, 'practice_manager');
        $this->as($admin)->get($this->joinUrl($session))->assertOk()->assertSee('DAILY_API_KEY')->assertSee('Telehealth settings');

        $this->assertSame([], $this->daily->calls);
    }

    #[Test]
    public function placeholder_keys_count_as_not_set_up(): void
    {
        foreach (['', '   ', 'REPLACE_ME', 'changeme', 'your-api-key', 'xxxxxxxxxxxx', '<your Daily key>'] as $i => $placeholder) {
            config(['services.daily.fake' => false, 'services.daily.api_key' => $placeholder]);
            $session = $this->sessionAt('2026-10-'.(10 + $i).' 10:00:00', room: false);

            $this->assertNull(app(PrepareRoom::class)($session), "[{$placeholder}] was treated as a key");
        }
        // Http::preventStrayRequests() would have failed any request.
        $this->assertSame([], $this->daily->calls);
    }

    #[Test]
    public function a_lost_race_gives_its_room_back_and_uses_the_winners(): void
    {
        $this->realDaily();
        $session = $this->sessionAt('2026-10-02 08:30:00', room: false);
        Http::fake([
            'api.daily.co/v1/rooms' => function () use ($session) {
                // Another request stores its room while ours is being created.
                DB::table('telehealth_sessions')->where('id', $session->id)->update([
                    'provider_room_name' => 'WinnerRoom0000000000', 'join_url' => Crypt::encryptString('https://wellnest.daily.co/WinnerRoom0000000000'),
                    'provider_room_nbf' => '2026-10-02 08:15:00+00', 'provider_room_exp' => '2026-10-02 11:30:00+00',
                ]);

                return Http::response(['name' => 'LoserRoom00000000000', 'url' => 'https://wellnest.daily.co/LoserRoom00000000000']);
            },
            'api.daily.co/v1/rooms/LoserRoom00000000000' => Http::response(['deleted' => true]),
        ]);

        $room = app(PrepareRoom::class)($session);

        $this->assertSame('WinnerRoom0000000000', $room?->name);
        $this->assertSame('WinnerRoom0000000000', $session->refresh()->provider_room_name);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.daily.co/v1/rooms/LoserRoom00000000000');
    }

    #[Test]
    public function finished_sessions_get_no_room_and_a_room_past_its_time_is_not_recreated(): void
    {
        $cancelled = $this->sessionAt('2026-10-06 10:00:00', room: false);
        app(TransitionAppointment::class)(Appointment::query()->findOrFail($cancelled->appointment_id), AppointmentStatus::Cancelled, $this->actor, null, CancellationKind::Practice);
        $this->assertNull(app(PrepareRoom::class)($cancelled->refresh()));

        // Still "upcoming" (nobody marked it), but its room's time is long over: refused, nothing created.
        $stale = $this->sessionAt('2026-10-02 08:00:00', room: false);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC'));
        try {
            app(PrepareRoom::class)($stale);
            $this->fail('A room was prepared after its time.');
        } catch (DomainException $e) {
            $this->assertSame('video_closed', $e->errorCode());
        }
        $this->as($this->drA)->get($this->joinUrl($stale))->assertOk()->assertSee('The time for this session has passed.');
        $this->assertSame([], $this->daily->callsOf('rooms.create'));
    }

    // ── releasing rooms ──────────────────────────────────────────────────────

    #[Test]
    public function cancelling_a_no_show_and_moving_to_in_person_release_the_room_after_commit(): void
    {
        $cancelled = $this->sessionAt('2026-10-06 10:00:00');
        app(TransitionAppointment::class)(Appointment::query()->findOrFail($cancelled->appointment_id), AppointmentStatus::Cancelled, $this->actor, null, CancellationKind::Practice);

        $this->travelTo(CarbonImmutable::parse('2026-10-06 11:30:00', 'UTC'));
        $missed = $this->sessionAt('2026-10-06 11:00:00', $this->drB, $this->clientB);
        app(TransitionAppointment::class)(Appointment::query()->findOrFail($missed->appointment_id), AppointmentStatus::NoShow, $this->actor);

        $moved = $this->sessionAt('2026-10-08 10:00:00');
        DB::table('appointments')->where('id', $moved->appointment_id)->update(['modality' => 'in_person', 'location_id' => $this->accra->id]);
        app(ReconcileSessions::class)();

        $deleted = array_column($this->daily->callsOf('rooms.delete'), 'name');
        $this->assertEqualsCanonicalizing([$cancelled->provider_room_name, $missed->provider_room_name, $moved->provider_room_name], $deleted);
        $this->assertSame(SessionStatus::Cancelled, $moved->refresh()->status);
    }

    #[Test]
    public function a_session_without_a_room_releases_nothing(): void
    {
        $session = $this->sessionAt('2026-10-06 10:00:00', room: false);
        app(TransitionAppointment::class)(Appointment::query()->findOrFail($session->appointment_id), AppointmentStatus::Cancelled, $this->actor, null, CancellationKind::Practice);

        $this->assertSame([], $this->daily->calls);
    }

    // ── passes ───────────────────────────────────────────────────────────────

    #[Test]
    public function a_pass_needs_a_running_session(): void
    {
        $session = $this->sessionAt('2026-10-02 08:05:00');

        try {
            app(IssueCallPass::class)($session, $this->drA);
            $this->fail('A pass was issued for a session that has not started.');
        } catch (DomainException $e) {
            $this->assertSame('session_not_running', $e->errorCode());
        }
        $this->assertSame([], $this->daily->callsOf('meeting-tokens.create'));
    }

    #[Test]
    public function owners_are_the_clinician_and_telehealth_managers_and_recording_needs_setting_consent_and_clinical_access(): void
    {
        $session = $this->sessionAt('2026-10-02 08:05:00');
        app(OpenSession::class)($session, $this->actor);
        $supervisor = $this->addStaff($this->organization, 'supervisor');       // sees all, notes, no telehealth.manage
        $this->revokeFromRole($this->organization, 'practice_manager', 'telehealth.notes');
        $manager = $this->addStaff($this->organization, 'practice_manager');    // manages telehealth, no clinical access

        $pass = function ($viewer) use ($session): array {
            app(IssueCallPass::class)($session->refresh(), $viewer);
            $tokens = $this->daily->callsOf('meeting-tokens.create');

            return end($tokens)['properties'];
        };

        $drA = $pass($this->drA);
        $this->assertSame($session->provider_room_name, $drA['room_name']);
        $this->assertSame($this->drA->id, $drA['user_id']);
        $this->assertSame($this->drA->professionalName(), $drA['user_name']);
        $this->assertTrue($drA['is_owner']);
        $this->assertFalse($drA['enable_prejoin_ui']);
        $this->assertSame(CarbonImmutable::parse('2026-10-02 11:05:00', 'UTC')->getTimestamp(), $drA['exp'], 'the room\'s end, inside the four-hour cap');
        $this->assertArrayNotHasKey('enable_recording', $drA, 'recording is off for the organization');

        $this->assertFalse($pass($supervisor)['is_owner']);
        $this->assertTrue($pass($manager)['is_owner']);

        $this->consented($session->refresh());
        $this->assertSame('cloud', $pass($this->drA)['enable_recording']);
        $this->assertTrue($pass($this->drA)['enable_recording_ui']);
        $this->assertSame('cloud', $pass($supervisor)['enable_recording'] ?? null, 'telehealth.notes on a session they see');
        $this->assertArrayNotHasKey('enable_recording', $pass($manager), 'no clinical access, no recording');

        $this->setting('telehealth.recording_enabled', false);
        $this->assertArrayNotHasKey('enable_recording', $pass($this->drA), 'the organization switched recording off');
    }

    #[Test]
    public function withdrawing_consent_during_a_call_stops_a_recording_and_before_the_call_calls_nobody(): void
    {
        $session = $this->consented($this->sessionAt('2026-10-02 08:05:00'));
        app(RecordConsent::class)($session, false, $this->actor);
        $this->assertSame([], $this->daily->callsOf('recordings.stop'), 'nothing runs before the call');

        app(RecordConsent::class)($session->refresh(), true, $this->actor);
        app(OpenSession::class)($session->refresh(), $this->actor);
        app(RecordConsent::class)($session->refresh(), false, $this->actor);

        $this->assertSame([['room' => $session->provider_room_name]], $this->daily->callsOf('recordings.stop'));
        $this->assertFalse($session->refresh()->consent_to_record);
    }

    #[Test]
    public function rooms_in_another_organization_are_out_of_reach(): void
    {
        $foreign = $this->inTenant($this->other, fn () => TelehealthSession::query()->where('appointment_id', $this->otherAppointment->id)->firstOrFail());

        $this->expectException(TenantMismatch::class);
        app(PrepareRoom::class)($foreign);
    }

    #[Test]
    public function a_telehealth_appointment_booked_in_person_first_gets_its_room_only_when_the_join_page_opens(): void
    {
        $appointment = $this->book($this->drA, $this->clientA, '2026-10-02 08:05:00', Modality::Telehealth);
        $session = $this->sessionOf($appointment);
        $this->assertSame([], $this->daily->calls);

        $this->as($this->drA)->get($this->joinUrl($session))->assertOk()->assertSee('Copy Meeting Link');
        $this->assertSame(['rooms.create'], $this->dailyOperations());
        $this->assertNotNull($session->refresh()->provider_room_name);
    }
}
