<?php

namespace Tests\Feature\Telehealth;

use App\Domain\Settings\SettingsService;
use App\Domain\Telehealth\AddTranscript;
use App\Domain\Telehealth\EndSession;
use App\Domain\Telehealth\OpenSession;
use App\Domain\Telehealth\TranscriptSource;
use App\Models\OrganizationMembership;
use App\Models\TelehealthSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/** Notes, consent, recordings, transcripts and the settings page over HTTP: authorization, headers, audit, validation. */
class TelehealthActionsHttpTest extends TelehealthTestCase
{
    private OrganizationMembership $manager;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['telehealth.join', 'telehealth.notes'] as $permission) {
            $this->revokeFromRole($this->organization, 'practice_manager', $permission);
        }
        $this->manager = $this->addStaff($this->organization, 'practice_manager');
    }

    private function finished(?string $start = '2026-10-02 08:00:00'): TelehealthSession
    {
        $session = $this->sessionAt($start);
        $this->travelTo(CarbonImmutable::parse($start, 'UTC'));
        app(OpenSession::class)($session, $this->actor);
        $this->travelTo(CarbonImmutable::parse($start, 'UTC')->addHour());
        app(EndSession::class)($session, $this->actor);

        return $session->refresh();
    }

    // ── notes ────────────────────────────────────────────────────────────────

    #[Test]
    public function the_clinician_saves_notes_as_versions_and_the_text_stays_out_of_the_audit_log(): void
    {
        $session = $this->finished();
        $url = $this->url('app.telehealth.notes.update', ['session' => $session->id]);

        $this->as($this->drA)->put($url, ['notes' => 'First draft of the summary.'])->assertRedirect($this->url('app.telehealth.show', ['session' => $session->id]));
        $this->put($url, ['notes' => 'Revised summary.'])->assertRedirect();
        $this->put($url, ['notes' => 'Revised summary.'])->assertRedirect()->assertSessionHas('success', 'The notes are already up to date.');

        $this->assertSame(2, DB::table('session_note_versions')->count());
        $this->get($this->url('app.telehealth.show', ['session' => $session->id]))->assertSee('Revised summary.')->assertDontSee('First draft of the summary.');
        $audit = json_encode(DB::table('audit_logs')->where('action', 'like', 'telehealth.notes_%')->get());
        $this->assertStringNotContainsString('First draft', $audit);
        $this->assertStringNotContainsString('Revised', $audit);

        $this->put($url, ['notes' => ''])->assertSessionHasErrors('notes');
        $this->put($url, ['notes' => str_repeat('x', 20001)])->assertSessionHasErrors('notes');
        $this->put($url, ['notes' => ['array']])->assertSessionHasErrors('notes');
    }

    #[Test]
    public function notes_are_escaped_when_shown(): void
    {
        $session = $this->finished();
        $this->as($this->drA)->put($this->url('app.telehealth.notes.update', ['session' => $session->id]), ['notes' => '<script>alert(1)</script><b>bold</b>']);

        $html = $this->get($this->url('app.telehealth.show', ['session' => $session->id]))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;&lt;b&gt;bold&lt;/b&gt;', $html);
    }

    #[Test]
    public function another_clinician_without_the_notes_permission_cannot_write_notes_on_a_session_they_do_not_own(): void
    {
        $session = $this->finished();
        $url = $this->url('app.telehealth.notes.update', ['session' => $session->id]);

        // drB sees only their own sessions: this one is not found at all.
        $this->as($this->drB)->put($url, ['notes' => 'x'])->assertNotFound();

        // A supervisor sees everything and holds telehealth.notes: allowed. Without it: forbidden.
        $supervisor = $this->addStaff($this->organization, 'supervisor');
        $this->as($supervisor)->put($url, ['notes' => 'Supervisor note'])->assertRedirect();
        $this->revokeFromRole($this->organization, 'supervisor', 'telehealth.notes');
        $this->put($url, ['notes' => 'Another'])->assertForbidden();
    }

    // ── consent, recording, download ─────────────────────────────────────────

    #[Test]
    public function consent_is_recorded_only_when_the_organization_allows_recording_and_the_join_page_shows_the_control_then(): void
    {
        $session = $this->sessionAt('2026-10-02 08:05:00');
        $consent = $this->url('app.telehealth.consent', ['session' => $session->id]);

        $this->as($this->drA)->get($this->joinUrl($session))->assertOk()->assertDontSee('Client consents to recording');
        $this->put($consent, ['consent' => 1])->assertSessionHasErrors('consent');
        $this->assertFalse($session->refresh()->consent_to_record);

        $this->enableRecording();
        $this->get($this->joinUrl($session))->assertOk()->assertSee('Client consents to recording');
        $this->put($consent, ['consent' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($session->refresh()->consent_to_record);
        $this->assertSame($this->drA->user_id, $session->consent_recorded_by_user_id);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'telehealth.consent_recorded')->count());

        $this->put($consent, ['consent' => 'maybe'])->assertSessionHasErrors('consent');
    }

    #[Test]
    public function a_recording_is_uploaded_only_with_consent_and_served_with_private_headers_and_an_audit_entry(): void
    {
        $session = $this->finished();
        $this->enableRecording();
        $upload = $this->url('app.telehealth.recordings.store', ['session' => $session->id]);

        // No consent yet: refused, nothing stored.
        $this->as($this->drA)->post($upload, ['recording' => $this->wav()])->assertSessionHasErrors('recording');
        $this->assertSame(0, DB::table('session_recordings')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('telehealth'));

        $this->put($this->url('app.telehealth.consent', ['session' => $session->id]), ['consent' => 1]);
        $this->post($upload, ['recording' => $this->wav()])->assertRedirect($this->url('app.telehealth.show', ['session' => $session->id]));
        $recording = $session->recordings()->firstOrFail();

        $page = $this->get($this->url('app.telehealth.show', ['session' => $session->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Session Recording', $page);
        $this->assertStringNotContainsString($recording->storage_path, $page, 'the storage path is never shown');
        $this->assertStringNotContainsString('telehealth/'.$session->organization_id, $page);

        $response = $this->get($this->url('app.telehealth.recordings.download', ['session' => $session->id, 'recording' => $recording->id]))->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $entry = DB::table('audit_logs')->where('action', 'telehealth.recording_downloaded')->first();
        $this->assertNotNull($entry);
        $this->assertSame($recording->id, $entry->subject_id);
        $this->assertStringNotContainsString('telehealth/', json_encode($entry));
    }

    #[Test]
    public function an_upload_that_is_not_audio_or_video_is_refused_whatever_its_name_says(): void
    {
        $session = $this->consented($this->finished());

        $this->as($this->drA)->post($this->url('app.telehealth.recordings.store', ['session' => $session->id]), [
            'recording' => UploadedFile::fake()->createWithContent('rec.wav', "<?php echo 'x';"),
        ])->assertSessionHasErrors('recording');
        $this->assertSame(0, DB::table('session_recordings')->count());
    }

    #[Test]
    public function only_someone_allowed_to_see_the_clinical_content_can_download_a_recording(): void
    {
        $session = $this->finished();
        $recording = $this->recordingFor($session);
        $download = $this->url('app.telehealth.recordings.download', ['session' => $session->id, 'recording' => $recording->id]);

        $this->as($this->manager)->get($download)->assertForbidden();   // sees the session, no notes permission, not the clinician
        $this->as($this->drB)->get($download)->assertNotFound();        // does not see the session at all
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'telehealth.recording_downloaded')->count());

        $this->as($this->drA)->get($download)->assertOk();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'telehealth.recording_downloaded')->count());

        // A purged recording is gone, and a path that does not belong to the session is never read.
        DB::table('session_recordings')->where('id', $recording->id)->update(['storage_path' => 'telehealth/other/x.wav']);
        $this->get($download)->assertNotFound();
        DB::table('session_recordings')->where('id', $recording->id)->update(['purged_at' => now()]);
        $this->get($download)->assertNotFound();
    }

    // ── transcripts ──────────────────────────────────────────────────────────

    #[Test]
    public function a_transcript_stays_a_draft_until_the_clinician_marks_it_reviewed(): void
    {
        $session = $this->consented($this->finished());
        $this->setting('telehealth.ai_transcripts_enabled', true);
        $transcript = app(AddTranscript::class)($session, 'Clinician: Hello', TranscriptSource::Ai, null, $this->actor);
        $show = $this->url('app.telehealth.transcripts.show', ['session' => $session->id, 'transcript' => $transcript->id]);

        $this->as($this->drA)->get($this->url('app.telehealth.show', ['session' => $session->id]))->assertSee('AI Transcript (Draft)');
        $this->get($show)->assertSee('AI Transcript (Draft)')->assertSee('Mark as reviewed');

        $this->as($this->manager)->post($show.'/review')->assertForbidden();
        $this->assertSame('draft', $transcript->refresh()->status->value);

        $this->as($this->drA)->post($show.'/review')->assertRedirect($show);
        $this->get($show)->assertSee('Reviewed')->assertDontSee('Mark as reviewed');
        $this->get($this->url('app.telehealth.show', ['session' => $session->id]))->assertSee('AI Transcript')->assertDontSee('AI Transcript (Draft)');
        $this->post($show.'/review')->assertSessionHasErrors();
    }

    // ── end ──────────────────────────────────────────────────────────────────

    #[Test]
    public function ending_a_running_session_redirects_to_the_completed_page(): void
    {
        $session = $this->sessionAt('2026-10-02 08:05:00');
        $this->as($this->drA)->postJson($this->url('app.telehealth.start', ['session' => $session->id]))->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:40:00', 'UTC'));
        $this->post($this->url('app.telehealth.end', ['session' => $session->id]))->assertRedirect($this->url('app.telehealth.show', ['session' => $session->id]));

        $this->assertSame('completed', $session->refresh()->status->value);
        $this->get($this->joinUrl($session))->assertRedirect($this->url('app.telehealth.show', ['session' => $session->id]));
    }

    #[Test]
    public function a_session_joined_early_can_be_completed_before_its_scheduled_start(): void
    {
        // The reported failure: joined inside the window (08:00 for 08:05) and completed before 08:05 — the appointment
        // rule refused "Completed" until the scheduled start. A visit under way is never completed "too early".
        $session = $this->sessionAt('2026-10-02 08:05:00');
        $this->as($this->drA)->post($this->url('app.telehealth.start', ['session' => $session->id]))->assertRedirect($this->callUrl($session));

        $this->post($this->url('app.telehealth.end', ['session' => $session->id]))
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->url('app.telehealth.show', ['session' => $session->id]));

        $this->assertSame('completed', $session->refresh()->status->value);
        $this->assertSame('completed', DB::table('appointments')->where('id', $session->appointment_id)->value('status'));
    }

    #[Test]
    public function leaving_the_call_keeps_the_session_open_to_rejoin_or_complete(): void
    {
        $session = $this->sessionAt('2026-10-02 08:05:00');
        $this->as($this->drA)->post($this->url('app.telehealth.start', ['session' => $session->id]))->assertRedirect($this->callUrl($session));

        // "Leave call" is a plain way back to the join page: no state change; the session is still in progress there.
        $call = $this->get($this->callUrl($session))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.$this->joinUrl($session).'"', $call);
        $this->assertStringContainsString('data-call-leave', $call);
        $this->assertStringContainsString('Complete session', $call);
        $this->assertStringNotContainsString('End session', $call);

        $join = $this->get($this->joinUrl($session))->assertOk()->getContent();
        $this->assertSame('in_progress', $session->refresh()->status->value);
        $this->assertStringContainsString('Rejoin Session', $join);
        $this->assertStringContainsString('Complete this session?', $join);
        $this->assertStringNotContainsString('End session', $join);
    }

    // ── settings ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_settings_page_needs_the_manage_permission_and_lists_itself_in_the_settings_navigation(): void
    {
        $this->as($this->drA)->get($this->url('app.settings.telehealth.edit'))->assertForbidden();

        $html = $this->as($this->manager)->get($this->url('app.settings.telehealth.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('Allow session recordings', $html);
        $this->assertStringContainsString('wait in a lobby until the clinician', $html);
        $this->assertStringNotContainsString('Allowed meeting-link hosts', $html);
        $this->assertStringNotContainsString('Default meeting link', $html);
        $this->assertStringContainsString('app/settings/telehealth', str_replace(['\\/', route('app.settings.telehealth.edit', ['organization' => $this->organization->slug])], ['/', 'app/settings/telehealth'], $html));
    }

    #[Test]
    public function the_settings_page_shows_whether_the_video_service_is_set_up(): void
    {
        $this->as($this->manager)->get($this->url('app.settings.telehealth.edit'))->assertOk()
            ->assertSee('Simulated (local development)')->assertSee('nothing reaches Daily');

        $this->videoNotConfigured();
        $this->get($this->url('app.settings.telehealth.edit'))->assertOk()->assertSee('Not set up')->assertSee('DAILY_API_KEY');

        $this->realDaily();
        $html = $this->get($this->url('app.settings.telehealth.edit'))->assertOk()->assertSee('Connected')->getContent();
        $this->assertStringNotContainsString(self::API_KEY, $html, 'the key is never shown');
        $this->assertSame([], $this->daily->calls, 'showing the status calls nobody');
    }

    #[Test]
    public function the_settings_form_saves_the_join_window_and_opt_ins_and_refuses_the_retired_link_settings(): void
    {
        $url = $this->url('app.settings.telehealth.update');
        $valid = ['join_early_minutes' => 20, 'recording_enabled' => '1'];

        $this->as($this->manager)->put($url, $valid + ['default_link' => 'https://zoom.us/j/1', 'allowed_hosts' => '*'])
            ->assertRedirect($this->url('app.settings.telehealth.edit'));
        $settings = app(SettingsService::class);
        $this->assertSame(20, $settings->organization($this->organization, 'telehealth.join_early_minutes'));
        $this->assertTrue($settings->organization($this->organization, 'telehealth.recording_enabled'));
        $this->assertFalse($settings->organization($this->organization, 'telehealth.ai_transcripts_enabled'));
        $this->assertSame(0, DB::table('organization_settings')->whereIn('key', ['telehealth.default_link_secret', 'telehealth.allowed_hosts'])->count(), 'retired fields are ignored');

        foreach ([['join_early_minutes' => 500], ['join_early_minutes' => -1], ['join_early_minutes' => 'soon'], ['recording_enabled' => 'maybe']] as $bad) {
            $this->put($url, $bad + $valid)->assertSessionHasErrors();
        }

        // The registry no longer knows the link settings at all.
        foreach (['telehealth.default_link_secret', 'telehealth.allowed_hosts'] as $retired) {
            try {
                $settings->organization($this->organization, $retired);
                $this->fail("{$retired} is still a setting.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function the_list_and_pages_are_free_of_inline_script_and_unescaped_user_text(): void
    {
        $evil = $this->client(['first_name' => '<img src=x onerror=alert(1)>', 'last_name' => 'Tester']);
        $session = $this->sessionAt('2026-10-02 08:05:00', $this->drA, $evil);

        $this->as($this->drA)->postJson($this->url('app.telehealth.start', ['session' => $session->id]))->assertOk();

        foreach ([$this->url('app.telehealth.index'), $this->joinUrl($session), $this->url('app.telehealth.check'), $this->callUrl($session)] as $url) {
            $html = $this->as($this->drA)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<img src=x', $html, $url);
            $this->assertStringNotContainsString('{!!', $html);
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html, $url);
        }
    }
}
