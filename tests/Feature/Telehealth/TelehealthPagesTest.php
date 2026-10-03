<?php

namespace Tests\Feature\Telehealth;

use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\CancellationKind;
use App\Domain\Scheduling\Events\AppointmentScheduled;
use App\Domain\Scheduling\Events\AppointmentStatusChanged;
use App\Domain\Scheduling\Modality;
use App\Domain\Scheduling\TransitionAppointment;
use App\Domain\Telehealth\AddTranscript;
use App\Domain\Telehealth\EndSession;
use App\Domain\Telehealth\OpenSession;
use App\Domain\Telehealth\SaveSessionNotes;
use App\Domain\Telehealth\SetMeetingLink;
use App\Domain\Telehealth\TranscriptSource;
use App\Models\Appointment;
use App\Models\OrganizationMembership;
use App\Models\TelehealthSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/** The sessions list, join page and completed page: who sees what, tenant isolation, windows, headers, query bounds. */
class TelehealthPagesTest extends TelehealthTestCase
{
    private OrganizationMembership $manager;

    private OrganizationMembership $reception;

    protected function setUp(): void
    {
        parent::setUp();
        // The manager template holds every telehealth permission (it must, to hand out the clinician role); this
        // organization's manager is configured to see everything and manage settings but not to join or read notes.
        foreach (['telehealth.join', 'telehealth.notes'] as $permission) {
            $this->revokeFromRole($this->organization, 'practice_manager', $permission);
        }
        $this->manager = $this->addStaff($this->organization, 'practice_manager');
        $this->reception = $this->addStaff($this->organization, 'receptionist');   // no telehealth permission at all
    }

    private function index(array $query = []): TestResponse
    {
        return $this->get($this->url('app.telehealth.index', $query));
    }

    // ── access ───────────────────────────────────────────────────────────────

    #[Test]
    public function the_pages_need_the_plan_a_telehealth_permission_and_a_signed_in_member(): void
    {
        $session = $this->sessionAt('2026-10-06 10:00:00');

        auth()->logout();
        $this->index()->assertRedirect(route('login'));

        $this->as($this->reception)->get($this->url('app.telehealth.index'))->assertForbidden();
        $this->get($this->url('app.telehealth.check'))->assertForbidden();
        $this->get($this->url('app.telehealth.show', ['session' => $session->id]))->assertForbidden();

        // Managing settings is not joining: the manager sees the list but may not open the join page.
        $this->as($this->manager)->get($this->url('app.telehealth.index'))->assertOk();
        $this->get($this->joinUrl($session))->assertForbidden();

        // A plan without telehealth shuts the module for everyone in it.
        $starter = $this->createOrganization(['name' => 'Starter Clinic'], plan: 'starter');
        $member = $this->addStaff($starter->organization, 'clinician');
        $this->as($member)->get(route('app.telehealth.index', ['organization' => $starter->organization->slug]))->assertForbidden();
    }

    #[Test]
    public function a_clinician_sees_only_their_own_sessions_and_view_all_sees_everyone_s(): void
    {
        $this->sessionAt('2026-10-06 10:00:00', $this->drA, $this->clientA);
        $this->sessionAt('2026-10-06 12:00:00', $this->drB, $this->clientB);

        $own = $this->as($this->drA)->get($this->url('app.telehealth.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Alice Alpha', $own);
        $this->assertStringNotContainsString('Bruno Bravo', $own);
        $this->assertStringContainsString('Upcoming (1)', $own);

        $all = $this->as($this->manager)->get($this->url('app.telehealth.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Alice Alpha', $all);
        $this->assertStringContainsString('Bruno Bravo', $all);
        $this->assertStringContainsString('Upcoming (2)', $all);
        $this->assertStringContainsString('Showing 1–2 of 2 sessions', $all);

        $this->as($this->drA)->get($this->joinUrl(TelehealthSession::query()->where('clinician_membership_id', $this->drB->id)->firstOrFail()))->assertNotFound();
    }

    #[Test]
    public function the_tabs_split_upcoming_from_past_and_count_them(): void
    {
        $upcoming = $this->sessionAt('2026-10-06 10:00:00');
        $finished = $this->sessionAt('2026-10-02 08:00:00', $this->drB, $this->clientB);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:00:00', 'UTC'));
        app(OpenSession::class)($finished, $this->actor);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:50:00', 'UTC'));
        app(EndSession::class)($finished, $this->actor);

        $page = $this->as($this->manager)->get($this->url('app.telehealth.index', ['tab' => 'upcoming']))->assertOk()->getContent();
        $this->assertStringContainsString('Upcoming Telehealth Sessions', $page);
        $this->assertStringContainsString('Alice Alpha', $page);
        $this->assertStringNotContainsString('Bruno Bravo', $page);
        $this->assertStringContainsString('Upcoming (1)', $page);

        $past = $this->get($this->url('app.telehealth.index', ['tab' => 'past']))->assertOk()->getContent();
        $this->assertStringContainsString('Bruno Bravo', $past);
        $this->assertStringNotContainsString('Alice Alpha', $past);
        $this->assertMatchesRegularExpression('/tele-pill--success">Completed</', $past);

        $all = $this->get($this->url('app.telehealth.index', ['tab' => 'all']))->assertOk()->getContent();
        $this->assertStringContainsString('Alice Alpha', $all);
        $this->assertStringContainsString('Bruno Bravo', $all);
        $this->assertStringContainsString('Showing 1–2 of 2 sessions', $all);

        // An unknown tab is the default tab, never an error.
        $this->get($this->url('app.telehealth.index', ['tab' => '"><script>']))->assertOk()->assertSee('Upcoming Telehealth Sessions');
    }

    #[Test]
    public function join_shows_only_from_fifteen_minutes_before_the_start_until_the_end(): void
    {
        $soon = $this->sessionAt('2026-10-02 08:10:00', $this->drA, $this->clientA);   // 10 minutes away: Join
        $later = $this->sessionAt('2026-10-02 10:00:00', $this->drA, $this->clientB);  // two hours away: View
        $other = $this->sessionAt('2026-10-02 14:00:00', $this->drB, $this->clientB, link: null);

        $html = $this->as($this->drA)->get($this->url('app.telehealth.index'))->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Join session with Alice Alpha"', $html);
        $this->assertStringContainsString('aria-label="View session with Bruno Bravo"', $html);
        $this->assertStringContainsString($this->joinUrl($soon), $html);
        $this->assertStringNotContainsString($this->joinUrl($later), $html, 'a session that is not yet joinable has no Join link');

        // The right rail's "Join Session" leads to the next session; the page never hands out meeting links.
        $this->assertStringContainsString('Ready for your session?', $html);
        $this->assertStringNotContainsString('Secret123', $html);
        $this->assertStringNotContainsString('zoom.us', $html);
    }

    // ── tenant isolation ─────────────────────────────────────────────────────

    #[Test]
    public function another_organizations_session_recording_and_transcript_ids_are_not_found(): void
    {
        $foreign = $this->inTenant($this->other, function () {
            $session = TelehealthSession::query()->where('appointment_id', $this->otherAppointment->id)->firstOrFail();
            app(SetMeetingLink::class)($session, 'https://zoom.us/j/999', null);

            return $session;
        });
        $mine = $this->sessionAt('2026-10-06 10:00:00');
        $recording = $this->recordingFor($mine);

        $this->actingAs($this->actor)->get($this->url('app.telehealth.show', ['session' => $foreign->id]))->assertNotFound();
        $this->get($this->joinUrl($foreign))->assertNotFound();
        $this->post($this->url('app.telehealth.start', ['session' => $foreign->id]))->assertNotFound();
        $this->put($this->url('app.telehealth.notes.update', ['session' => $foreign->id]), ['notes' => 'x'])->assertNotFound();
        $this->put($this->url('app.telehealth.consent', ['session' => $foreign->id]), ['consent' => 1])->assertNotFound();
        $this->get($this->url('app.telehealth.recordings.download', ['session' => $foreign->id, 'recording' => $recording->id]))->assertNotFound();

        // A recording of my own session cannot be fetched through another session's URL either (scoped bindings).
        $second = $this->sessionAt('2026-10-07 10:00:00', $this->drA, $this->clientB);
        $this->get($this->url('app.telehealth.recordings.download', ['session' => $second->id, 'recording' => $recording->id]))->assertNotFound();

        // And the other organization's user cannot reach mine under their own slug.
        $outsider = $this->addStaff($this->other, 'clinician');
        $this->as($outsider)->get(route('app.telehealth.join', ['organization' => $this->other->slug, 'session' => $mine->id]))->assertNotFound();
    }

    // ── join page ────────────────────────────────────────────────────────────

    #[Test]
    public function the_join_page_shows_the_session_the_preview_and_the_link_to_the_people_who_may_join(): void
    {
        $this->setting('general.date_format', 'M j, Y');
        $this->setting('general.time_format', 'g:i A');
        $session = $this->sessionAt('2026-10-02 08:05:00');

        $response = $this->as($this->drA)->get($this->joinUrl($session))->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Join Telehealth Session', $html);
        $this->assertStringContainsString('Alice Alpha', $html);
        $this->assertStringContainsString('Oct 2, 2026', $html);
        $this->assertStringContainsString('8:05 AM – 9:05 AM', $html);
        $this->assertStringContainsString('Zoom Meeting', $html);
        $this->assertStringContainsString('Ready to join?', $html);
        $this->assertStringContainsString('data-telehealth-preview', $html);
        $this->assertStringContainsString('<a href="'.e(self::LINK).'" target="_blank" rel="noopener noreferrer" class="tj-join"', $html);
        $this->assertStringContainsString('data-url="'.e(self::LINK).'"', $html);
        $this->assertStringNotContainsString('<script>', str_replace('<script src=', '', $html), 'no inline script');
        $response->assertSee('js/screens/telehealth.js', false);
    }

    #[Test]
    public function before_the_window_opens_the_join_button_is_disabled_and_says_when_it_opens(): void
    {
        $session = $this->sessionAt('2026-10-02 10:00:00');

        $html = $this->as($this->drA)->get($this->joinUrl($session))->assertOk()->getContent();

        $this->assertStringContainsString('tj-join is-disabled', $html);
        $this->assertStringContainsString('You can join from', $html);
        $this->assertStringNotContainsString('target="_blank"', $html);
    }

    #[Test]
    public function a_session_without_a_link_asks_for_one_and_a_bad_link_is_refused_with_a_message(): void
    {
        $session = $this->sessionAt('2026-10-02 08:05:00', link: null);

        $this->as($this->drA)->get($this->joinUrl($session))->assertOk()->assertSee('Paste the Zoom, Google Meet or Microsoft Teams link', false)->assertSee('Save link');

        $this->put($this->url('app.telehealth.link', ['session' => $session->id]), ['join_url' => 'http://zoom.us/j/1'])
            ->assertSessionHasErrors('join_url');
        $this->assertNull($session->refresh()->join_url);

        $this->put($this->url('app.telehealth.link', ['session' => $session->id]), ['join_url' => 'https://meet.google.com/abc-defg-hij'])
            ->assertRedirect($this->joinUrl($session));
        $this->assertSame('https://meet.google.com/abc-defg-hij', $session->refresh()->join_url);
    }

    #[Test]
    public function the_start_ping_marks_the_session_in_progress_inside_the_window_and_is_refused_outside_it(): void
    {
        $inside = $this->sessionAt('2026-10-02 08:05:00');
        $outside = $this->sessionAt('2026-10-02 12:00:00', $this->drA, $this->clientB);

        $this->as($this->drA)->postJson($this->url('app.telehealth.start', ['session' => $inside->id]))->assertOk()->assertJson(['status' => 'in_progress']);
        $this->assertSame('in_progress', $inside->refresh()->status->value);

        $this->postJson($this->url('app.telehealth.start', ['session' => $outside->id]))->assertStatus(422)->assertJson(['code' => 'outside_join_window']);
        $this->assertSame('scheduled', $outside->refresh()->status->value);
    }

    #[Test]
    public function the_device_check_and_join_pages_alone_allow_the_camera_and_microphone(): void
    {
        $session = $this->sessionAt('2026-10-02 08:05:00');
        $this->as($this->drA);

        foreach ([$this->url('app.telehealth.check'), $this->joinUrl($session)] as $url) {
            $policy = $this->get($url)->assertOk()->headers->get('Permissions-Policy');
            $this->assertStringContainsString('camera=(self)', $policy, $url);
            $this->assertStringContainsString('microphone=(self)', $policy, $url);
            $this->assertStringContainsString('geolocation=()', $policy, $url);
        }

        foreach ([$this->url('app.telehealth.index'), $this->url('app.calendar.index'), $this->url('app.telehealth.show', ['session' => $session->id])] as $url) {
            $policy = $this->get($url)->headers->get('Permissions-Policy');
            $this->assertStringContainsString('camera=()', $policy, $url);
            $this->assertStringContainsString('microphone=()', $policy, $url);
        }

        $csp = $this->get($this->joinUrl($session))->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
    }

    // ── completed page ───────────────────────────────────────────────────────

    #[Test]
    public function the_completed_page_shows_summary_notes_recording_transcript_and_the_next_appointment(): void
    {
        $this->setting('general.date_format', 'M j, Y');
        $this->setting('general.time_format', 'g:i A');
        $session = $this->sessionAt('2026-10-02 08:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:00:00', 'UTC'));
        app(OpenSession::class)($session, $this->actor);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC'));
        app(EndSession::class)($session, $this->actor);
        app(SaveSessionNotes::class)($session, 'Client discussed coping strategies.', $this->actor);
        $recording = $this->recordingFor($session);
        $this->setting('telehealth.ai_transcripts_enabled', true);
        app(AddTranscript::class)($session, 'Clinician: Hello', TranscriptSource::Ai, $recording, $this->actor);
        $this->book($this->drA, $this->clientA, '2026-10-09 10:00:00', Modality::InPerson);

        $html = $this->as($this->drA)->get($this->url('app.telehealth.show', ['session' => $session->id]))->assertOk()->getContent();

        $this->assertStringContainsString('Session Completed', $html);
        $this->assertStringContainsString('Your telehealth session with Alice Alpha has ended.', $html);
        $this->assertStringContainsString('(1h 00m)', $html);
        $this->assertStringContainsString('Client discussed coping strategies.', $html);
        $this->assertStringContainsString('Recording &amp; Transcript', $html);
        $this->assertStringContainsString('Client consented to recording', $html);
        $this->assertStringContainsString('AI Transcript (Draft)', $html);
        $this->assertStringContainsString('Generated from recording', $html);
        $this->assertStringContainsString('A follow-up session is scheduled for Oct 9, 2026 at 10:00 AM.', $html);
        $this->assertStringNotContainsString('Clinician: Hello', $html, 'the transcript text is on its own page');
        $this->assertStringNotContainsString('Secret123', $html);
    }

    #[Test]
    public function clinical_content_is_hidden_from_someone_who_may_see_the_session_but_not_its_notes(): void
    {
        $session = $this->sessionAt('2026-10-02 08:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:00:00', 'UTC'));
        app(OpenSession::class)($session, $this->actor);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC'));
        app(EndSession::class)($session, $this->actor);
        app(SaveSessionNotes::class)($session, 'Very private clinical note.', $this->actor);
        $this->recordingFor($session);

        // The practice manager sees every session (view all) but holds neither telehealth.notes nor is the clinician.
        $html = $this->as($this->manager)->get($this->url('app.telehealth.show', ['session' => $session->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Very private clinical note.', $html);
        $this->assertStringNotContainsString('Recording &amp; Transcript', $html);
        $this->assertStringNotContainsString('<textarea', $html);

        $this->put($this->url('app.telehealth.notes.update', ['session' => $session->id]), ['notes' => 'overwrite'])->assertForbidden();
        $this->get($this->url('app.telehealth.recordings.download', ['session' => $session->id, 'recording' => $session->recordings()->firstOrFail()->id]))->assertForbidden();
        $this->post($this->url('app.telehealth.consent', ['session' => $session->id]), ['consent' => 0])->assertStatus(405);
        $this->put($this->url('app.telehealth.consent', ['session' => $session->id]), ['consent' => 0])->assertForbidden();
    }

    #[Test]
    public function show_sends_an_unfinished_session_to_its_join_page_and_one_that_never_happened_to_the_list(): void
    {
        $open = $this->sessionAt('2026-10-06 10:00:00');
        $cancelled = $this->sessionAt('2026-10-07 10:00:00', $this->drA, $this->clientB);
        app(TransitionAppointment::class)(Appointment::query()->findOrFail($cancelled->appointment_id), AppointmentStatus::Cancelled, $this->actor, null, CancellationKind::Practice);

        $this->as($this->drA)->get($this->url('app.telehealth.show', ['session' => $open->id]))->assertRedirect($this->joinUrl($open));
        $this->get($this->url('app.telehealth.show', ['session' => $cancelled->id]))->assertRedirect($this->url('app.telehealth.index'));
        $this->get($this->joinUrl($cancelled))->assertRedirect($this->url('app.telehealth.index'));
    }

    // ── reconciliation ───────────────────────────────────────────────────────

    #[Test]
    public function opening_the_list_reconciles_an_appointment_whose_modality_was_edited(): void
    {
        $appointment = $this->book($this->drA, $this->clientA, '2026-10-06 10:00:00', Modality::InPerson);
        $this->assertSame(0, TelehealthSession::query()->count());

        // Edited to telehealth without being rebooked: no event fired, so no session yet.
        DB::table('appointments')->where('id', $appointment->id)->update(['modality' => 'telehealth', 'location_id' => null]);
        $this->as($this->drA)->get($this->url('app.telehealth.index'))->assertOk()->assertSee('Alice Alpha');
        $session = $this->sessionOf($appointment);
        $this->assertSame('scheduled', $session->status->value);

        // And back to in person: the open session is retired.
        DB::table('appointments')->where('id', $appointment->id)->update(['modality' => 'in_person', 'location_id' => $this->accra->id]);
        $this->get($this->url('app.telehealth.index'))->assertOk()->assertDontSee('Alice Alpha');
        $this->assertSame('cancelled', $session->refresh()->status->value);

        // Nothing to do: the next visit changes nothing and adds no history.
        $history = $session->statusHistory()->count();
        $this->get($this->url('app.telehealth.index'))->assertOk();
        $this->assertSame($history, $session->statusHistory()->count());
    }

    #[Test]
    public function scheduling_events_for_in_person_appointments_cost_the_telehealth_listener_no_queries(): void
    {
        $appointment = $this->book($this->drA, $this->clientA, '2026-10-06 10:00:00', Modality::InPerson);

        $queries = $this->queriesDuring(function () use ($appointment) {
            event(new AppointmentScheduled($appointment, $this->actor->id));
            event(new AppointmentStatusChanged($appointment, AppointmentStatus::Scheduled, AppointmentStatus::Confirmed, null, null));
        });

        $this->assertSame([], array_values(array_filter($queries, fn (string $sql) => str_contains($sql, 'telehealth_'))));
    }

    // ── query bounds ─────────────────────────────────────────────────────────

    #[Test]
    public function the_list_costs_the_same_queries_for_three_rows_as_for_twelve(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->sessionAt("2026-10-1{$i} 10:00:00", $this->drA, $i % 2 ? $this->clientA : $this->clientB);
        }
        $this->as($this->manager)->get($this->url('app.telehealth.index'))->assertOk(); // warm the memos
        $small = $this->queryCount(fn () => $this->get($this->url('app.telehealth.index'))->assertOk());

        foreach (range(4, 12) as $i) {
            $this->sessionAt(sprintf('2026-10-%02d 15:00:00', $i + 10), $this->drB, $i % 2 ? $this->clientA : $this->clientB);
        }
        $large = $this->queryCount(fn () => $this->get($this->url('app.telehealth.index'))->assertOk());

        $this->assertSame($small, $large, 'the list must not run per-row queries');
        $this->assertLessThanOrEqual(24, $large);
    }

    #[Test]
    public function the_completed_and_join_pages_use_a_fixed_number_of_queries(): void
    {
        $session = $this->sessionAt('2026-10-02 08:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:00:00', 'UTC'));
        app(OpenSession::class)($session, $this->actor);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC'));
        app(EndSession::class)($session, $this->actor);
        $this->recordingFor($session);
        $this->setting('telehealth.ai_transcripts_enabled', true);
        app(AddTranscript::class)($session, 'text', TranscriptSource::Ai, null, $this->actor);

        $this->as($this->drA)->get($this->url('app.telehealth.show', ['session' => $session->id]))->assertOk();
        $one = $this->queryCount(fn () => $this->get($this->url('app.telehealth.show', ['session' => $session->id]))->assertOk());

        app(AddTranscript::class)($session, 'more text', TranscriptSource::Provider, null, $this->actor);
        app(AddTranscript::class)($session, 'even more', TranscriptSource::Provider, null, $this->actor);
        $three = $this->queryCount(fn () => $this->get($this->url('app.telehealth.show', ['session' => $session->id]))->assertOk());

        $this->assertSame($one, $three);
        $this->assertLessThanOrEqual(30, $three);
    }

    #[Test]
    public function reading_pages_writes_no_audit_rows_but_opening_a_transcript_does(): void
    {
        $session = $this->consented($this->sessionAt('2026-10-02 08:00:00'));
        $transcript = app(AddTranscript::class)($session, 'secret words', TranscriptSource::Provider, null, $this->actor);
        $before = DB::table('audit_logs')->count();

        $this->as($this->drA)->get($this->url('app.telehealth.index'))->assertOk();
        $this->get($this->joinUrl($session))->assertOk();
        $this->assertSame($before, DB::table('audit_logs')->count());

        $this->get($this->url('app.telehealth.transcripts.show', ['session' => $session->id, 'transcript' => $transcript->id]))
            ->assertOk()->assertSee('secret words')->assertSee('Draft')->assertSee('has not been reviewed');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'telehealth.transcript_viewed')->count());
        $this->assertStringNotContainsString('secret words', json_encode(DB::table('audit_logs')->where('action', 'telehealth.transcript_viewed')->get()));
    }
}
