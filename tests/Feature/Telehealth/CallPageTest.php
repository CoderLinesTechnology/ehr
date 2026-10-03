<?php

namespace Tests\Feature\Telehealth;

use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\CancellationKind;
use App\Domain\Scheduling\TransitionAppointment;
use App\Domain\Telehealth\EndSession;
use App\Domain\Telehealth\OpenSession;
use App\Models\Appointment;
use App\Models\OrganizationMembership;
use App\Models\TelehealthSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

/** Join → call: the in-app call page with Daily Prebuilt framed, its headers, its states and who may open it. */
class CallPageTest extends TelehealthTestCase
{
    private function running(string $start = '2026-10-02 08:05:00', ?OrganizationMembership $clinician = null): TelehealthSession
    {
        $session = $this->sessionAt($start, $clinician);
        app(OpenSession::class)($session, $this->actor);

        return $session->refresh();
    }

    /** The frame URL printed in the page (the pass). */
    private function frameSrc(string $html): string
    {
        $this->assertSame(1, preg_match('/<iframe class="tv-frame" src="([^"]+)"/', $html, $m), 'the call page frames Daily');

        return html_entity_decode($m[1]);
    }

    #[Test]
    public function joining_opens_the_session_and_leads_to_the_call_page_with_daily_framed(): void
    {
        $session = $this->sessionAt('2026-10-02 08:05:00');

        $this->as($this->drA)->post($this->url('app.telehealth.start', ['session' => $session->id]))->assertRedirect($this->callUrl($session));
        $this->assertSame('in_progress', $session->refresh()->status->value);

        $response = $this->get($this->callUrl($session))->assertOk();
        $html = $response->getContent();
        $src = $this->frameSrc($html);
        $this->assertStringStartsWith($session->join_url.'?t=fake-token-', $src);
        $this->assertStringContainsString('allow="camera; microphone; autoplay; display-capture; fullscreen"', $html);
        $this->assertStringContainsString('title="Video call with Alice Alpha"', $html);
        $this->assertStringContainsString('In progress', $html);
        $this->assertStringContainsString('Copy Meeting Link', $html);
        $this->assertStringContainsString('data-url="'.e($session->join_url).'"', $html);
        $this->assertStringContainsString('End session', $html);

        // No Daily script, no inline script: only our own files.
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script[^>]+src="https?:\/\/(?!localhost)[^"]*daily/i', $html);
        $this->assertSame(['rooms.create', 'meeting-tokens.create'], $this->dailyOperations());
    }

    #[Test]
    public function only_the_call_page_may_frame_daily_and_delegate_camera_microphone_and_screen_sharing(): void
    {
        $session = $this->running();
        $this->as($this->drA);

        $call = $this->get($this->callUrl($session))->assertOk();
        $csp = (string) $call->headers->get('Content-Security-Policy');
        // Daily's domains, and our own origin for the app frame the user browses in while the call floats.
        $this->assertStringContainsString("frame-src 'self' https://*.daily.co https://*.dailywebrtc.com https://*.dailywebrtc.net", $csp);
        $this->assertStringContainsString("script-src 'self';", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);   // a staff-app page: framed by WellNest only
        $policy = (string) $call->headers->get('Permissions-Policy');
        foreach (['camera=*', 'microphone=*', 'display-capture=*', 'fullscreen=*', 'autoplay=*', 'geolocation=()', 'payment=()'] as $feature) {
            $this->assertStringContainsString($feature, $policy);
        }
        $this->assertStringContainsString('no-store', (string) $call->headers->get('Cache-Control'));

        foreach ([$this->joinUrl($session), $this->url('app.telehealth.index'), $this->url('app.telehealth.check'), $this->url('app.calendar.index')] as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertStringNotContainsString('frame-src', (string) $response->headers->get('Content-Security-Policy'), $url);
            $this->assertStringNotContainsString('camera=*', (string) $response->headers->get('Permissions-Policy'), $url);
        }
    }

    #[Test]
    public function the_call_page_needs_a_running_session_and_the_join_permission(): void
    {
        $upcoming = $this->sessionAt('2026-10-06 10:00:00');
        $this->as($this->drA)->get($this->callUrl($upcoming))->assertRedirect($this->joinUrl($upcoming));

        $session = $this->running();
        $this->as($this->drB)->get($this->callUrl($session))->assertNotFound();               // not their session
        $this->revokeFromRole($this->organization, 'practice_manager', 'telehealth.join');
        $this->as($this->addStaff($this->organization, 'practice_manager'))->get($this->callUrl($session))->assertForbidden();
        $this->as($this->addStaff($this->organization, 'receptionist'))->get($this->callUrl($session))->assertForbidden();
        $this->assertSame([], $this->daily->callsOf('meeting-tokens.create'), 'refused viewers get no pass');

        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:40:00', 'UTC'));
        app(EndSession::class)($session, $this->actor);
        $this->as($this->drA)->get($this->callUrl($session))->assertRedirect($this->url('app.telehealth.show', ['session' => $session->id]));

        $cancelled = $this->sessionAt('2026-10-07 10:00:00');
        app(TransitionAppointment::class)(Appointment::query()->findOrFail($cancelled->appointment_id), AppointmentStatus::Cancelled, $this->actor, null, CancellationKind::Practice);
        $this->get($this->callUrl($cancelled))->assertRedirect($this->joinUrl($cancelled));
    }

    #[Test]
    public function every_load_mints_a_fresh_pass_that_is_never_stored_logged_or_audited(): void
    {
        Log::spy();
        $session = $this->running();
        $auditBefore = DB::table('audit_logs')->count();

        $first = $this->frameSrc($this->as($this->drA)->get($this->callUrl($session))->assertOk()->getContent());
        $second = $this->frameSrc($this->get($this->callUrl($session))->assertOk()->getContent());

        $this->assertNotSame($first, $second);
        $this->assertCount(2, $this->daily->callsOf('meeting-tokens.create'));
        $this->assertSame($auditBefore, DB::table('audit_logs')->count(), 'opening the call writes no audit row');

        $token = substr($first, strpos($first, '?t=') + 3);
        $everything = json_encode([DB::table('audit_logs')->get(), DB::table('telehealth_sessions')->get(), DB::table('telehealth_session_status_histories')->get()]);
        $this->assertStringNotContainsString($token, $everything);
        Log::shouldNotHaveReceived('info', [\Mockery::on(fn ($m) => str_contains((string) $m, $token))]);
        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function the_recording_card_follows_the_setting_consent_and_clinical_access(): void
    {
        $session = $this->running();

        $this->as($this->drA)->get($this->callUrl($session))->assertOk()->assertDontSee('tv-rec-title', false);

        $this->enableRecording();
        $this->get($this->callUrl($session))->assertOk()->assertSee('Not recorded')->assertSee('Client consents to recording');
        $this->put($this->url('app.telehealth.consent', ['session' => $session->id]), ['consent' => 1])->assertRedirect();
        $this->get($this->callUrl($session))->assertOk()->assertSee('Client consented')->assertSee('Withdraw consent (stops recording)');
        $this->assertSame('cloud', last($this->daily->callsOf('meeting-tokens.create'))['properties']['enable_recording'] ?? null);

        // Someone who joins but may not see clinical content sees the state, not the control, and cannot record.
        $this->revokeFromRole($this->organization, 'practice_manager', 'telehealth.notes');
        $manager = $this->addStaff($this->organization, 'practice_manager');
        $this->as($manager)->get($this->callUrl($session))->assertOk()->assertSee('Client consented')->assertDontSee('Withdraw consent');
        $this->assertArrayNotHasKey('enable_recording', last($this->daily->callsOf('meeting-tokens.create'))['properties']);
    }

    #[Test]
    public function ending_from_the_call_page_completes_the_session_and_deletes_the_room(): void
    {
        $session = $this->running();
        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:45:00', 'UTC'));

        $this->as($this->drA)->post($this->url('app.telehealth.end', ['session' => $session->id]))->assertRedirect($this->url('app.telehealth.show', ['session' => $session->id]));

        $this->assertSame('completed', $session->refresh()->status->value);
        $this->assertSame([['name' => $session->provider_room_name]], $this->daily->callsOf('rooms.delete'));
    }

    #[Test]
    public function the_join_and_call_pages_use_a_fixed_number_of_queries_and_reuse_the_room(): void
    {
        $session = $this->running();
        $this->as($this->drA)->get($this->joinUrl($session))->assertOk();   // warm the memos
        $this->get($this->callUrl($session))->assertOk();

        $join = $this->queryCount(fn () => $this->get($this->joinUrl($session))->assertOk());
        $call = $this->queryCount(fn () => $this->get($this->callUrl($session))->assertOk());
        // Measured 21 and 23 (session, appointment, client, clinician, service, settings, permissions, the stored room).
        $this->assertLessThanOrEqual(24, $join);
        $this->assertLessThanOrEqual(26, $call);
        $this->assertSame(['rooms.create'], array_values(array_unique(array_diff($this->dailyOperations(), ['meeting-tokens.create']))), 'the room is created once and reused');
    }

    #[Test]
    public function a_failing_video_service_shows_a_notice_on_the_call_page_never_an_error(): void
    {
        $session = $this->running();
        $this->realDaily();
        Http::fake(['api.daily.co/v1/meeting-tokens' => Http::response(['error' => 'server-error'], 503)]);

        $this->as($this->drA)->get($this->callUrl($session))->assertOk()
            ->assertSee('The video service could not be reached')->assertSee('Try again')->assertDontSee('<iframe', false);

        $this->videoNotConfigured();
        $this->get($this->callUrl($session))->assertOk()->assertSee('Video calls are not set up yet')->assertDontSee('Try again');
    }
}
