<?php

namespace Tests\Feature\Telehealth;

use App\Domain\Settings\SettingsService;
use App\Domain\Telehealth\EndSession;
use App\Domain\Telehealth\OpenSession;
use App\Domain\Telehealth\PrepareRoom;
use App\Domain\Telehealth\RecordConsent;
use App\Models\TelehealthSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST /webhooks/daily: signature first (raw body, base64 HMAC-SHA256 with the base64-decoded secret, ±5 minutes),
 * then the recording events — stored once in the right organization with consent, deleted at Daily without it.
 */
class DailyWebhookTest extends TelehealthTestCase
{
    private const RECORDING = '0b5e7a52-6f0e-4b8a-9c41-2d6a8f1e3c77';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.daily.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    /** A delivery as Daily sends it: no session, no CSRF token, nobody signed in. */
    private function deliver(string $raw, ?string $secret = self::WEBHOOK_SECRET, ?string $timestamp = null, ?string $signature = null): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $timestamp ??= (string) now()->getTimestamp();
        $signature ??= $secret === null ? null : base64_encode(hash_hmac('sha256', $timestamp.'.'.$raw, base64_decode($secret), true));
        $server = array_filter(['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp, 'HTTP_X_WEBHOOK_SIGNATURE' => $signature]);

        return $this->call('POST', '/webhooks/daily', [], [], [], $server, $raw);
    }

    private function event(string $type, array $payload): string
    {
        return json_encode(['version' => '1.0.0', 'type' => $type, 'id' => 'evt-'.Str::random(8), 'payload' => $payload, 'event_ts' => 1759392000.123], JSON_UNESCAPED_SLASHES);
    }

    private function ready(string $room, string $recording = self::RECORDING): string
    {
        return $this->event('recording.ready-to-download', [
            'recording_id' => $recording, 'room_name' => $room, 'start_ts' => 1759392000, 'status' => 'finished',
            'max_participants' => 2, 'duration' => 1199.6, 's3_key' => 'wellnest/'.$room.'/1759392000',
        ]);
    }

    private function finished(bool $consent = true): TelehealthSession
    {
        $session = $this->sessionAt('2026-10-02 08:05:00');
        if ($consent) {
            $this->consented($session);
        }
        app(OpenSession::class)($session->refresh(), $this->actor);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:50:00', 'UTC'));
        app(EndSession::class)($session, $this->actor);

        return $session->refresh();
    }

    // ── signature ────────────────────────────────────────────────────────────

    #[Test]
    public function the_signed_verification_ping_is_answered_200_and_changes_nothing(): void
    {
        $response = $this->deliver('{"test":"test"}')->assertOk();

        $this->assertSame('', $response->getContent());
        $this->assertSame([], $response->headers->getCookies(), 'no session or CSRF cookie: the route is outside the web group');
        $this->assertSame(0, DB::table('session_recordings')->count());
    }

    #[Test]
    public function without_a_configured_secret_every_delivery_is_refused(): void
    {
        foreach ([null, '', 'REPLACE_ME', 'not base64 at all!', base64_encode('short')] as $configured) {
            config(['services.daily.webhook_secret' => $configured]);
            $this->deliver('{"test":"test"}')->assertUnauthorized();
        }
    }

    #[Test]
    public function wrong_stale_future_or_missing_signatures_are_refused_before_anything_is_read(): void
    {
        $session = $this->finished();
        $body = $this->ready($session->provider_room_name);
        $other = base64_encode(random_bytes(32));
        $now = now()->getTimestamp();

        $this->deliver($body, $other)->assertUnauthorized();                                                       // another secret
        $this->deliver($body, timestamp: (string) ($now - 301))->assertUnauthorized();                               // stale
        $this->deliver($body, timestamp: (string) ($now + 301))->assertUnauthorized();                               // from the future
        $this->deliver($body, timestamp: 'yesterday')->assertUnauthorized();
        $this->deliver($body, signature: 'AAAA')->assertUnauthorized();
        $this->deliver($body, secret: null)->assertUnauthorized();                                                    // no headers at all
        // Signed for one body, delivered with another (the raw bytes are what is signed).
        $signature = base64_encode(hash_hmac('sha256', $now.'.'.$body, base64_decode(self::WEBHOOK_SECRET), true));
        $this->deliver(str_replace('"duration":1199.6', '"duration":1', $body), timestamp: (string) $now, signature: $signature)->assertUnauthorized();

        $this->assertSame(0, DB::table('session_recordings')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'like', 'telehealth.recording_%')->count());
        $this->assertSame([], $this->daily->callsOf('recordings.delete'));

        // A millisecond timestamp (the docs do not say which unit) is accepted when signed as sent.
        $this->deliver('{"test":"test"}', timestamp: (string) ($now * 1000 + 250))->assertOk();
    }

    #[Test]
    public function an_oversized_body_is_refused_and_the_route_is_throttled_outside_the_web_group(): void
    {
        $this->deliver('{"test":"'.str_repeat('a', 262144).'"}')->assertStatus(413);

        $middleware = Route::getRoutes()->getByName('webhooks.daily')->gatherMiddleware();
        $this->assertContains('throttle:120,1', $middleware);
        $this->assertNotContains('web', $middleware);
        $this->assertNotContains('auth', $middleware);
    }

    // ── recording.ready-to-download ──────────────────────────────────────────

    #[Test]
    public function a_consented_recording_is_stored_once_in_its_sessions_organization(): void
    {
        $session = $this->finished();

        $this->deliver($this->ready($session->provider_room_name))->assertOk();
        $this->deliver($this->ready($session->provider_room_name))->assertOk();   // redelivered: still one row

        $rows = DB::table('session_recordings')->get();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame($session->organization_id, $row->organization_id);
        $this->assertSame($session->id, $row->telehealth_session_id);
        $this->assertSame('daily', $row->provider_key);
        $this->assertSame(self::RECORDING, $row->provider_recording_id);
        $this->assertSame('video/mp4', $row->mime);
        $this->assertSame(1200, (int) $row->duration_seconds);
        $this->assertNull($row->storage_path);
        $this->assertNull($row->size_bytes);

        $audit = DB::table('audit_logs')->where('action', 'telehealth.recording_attached')->get();
        $this->assertCount(1, $audit);
        $this->assertSame($session->organization_id, $audit[0]->organization_id);
        $this->assertNull($audit[0]->actor_user_id);
        $this->assertStringNotContainsString(self::RECORDING, json_encode($audit), 'Daily ids stay out of the audit trail');
        $this->assertStringNotContainsString($session->provider_room_name, json_encode($audit));

        // The completed page offers it for download, without a size it does not know.
        $this->as($this->drA)->get($this->url('app.telehealth.show', ['session' => $session->id]))->assertOk()
            ->assertSee('Session Recording')->assertSee('Kept by Daily')->assertDontSee(self::RECORDING);
    }

    #[Test]
    public function a_recording_for_another_organizations_room_lands_in_that_organization(): void
    {
        $foreign = $this->inTenant($this->other, function () {
            $session = TelehealthSession::query()->where('appointment_id', $this->otherAppointment->id)->firstOrFail();
            app(PrepareRoom::class)($session);
            app(SettingsService::class)->setOrganization($this->other, ['telehealth.recording_enabled' => true], null);

            return app(RecordConsent::class)($session->refresh(), true, null);
        });

        $this->deliver($this->ready($foreign->provider_room_name))->assertOk();

        $row = DB::table('session_recordings')->first();
        $this->assertSame($this->other->id, $row->organization_id);
        $this->assertSame($foreign->id, $row->telehealth_session_id);
    }

    #[Test]
    public function a_recording_without_consent_is_deleted_at_daily_and_never_stored(): void
    {
        $session = $this->finished(consent: false);

        $this->deliver($this->ready($session->provider_room_name))->assertOk();

        $this->assertSame(0, DB::table('session_recordings')->count());
        $this->assertSame([['id' => self::RECORDING]], $this->daily->callsOf('recordings.delete'));
        $audit = DB::table('audit_logs')->where('action', 'telehealth.recording_refused')->first();
        $this->assertNotNull($audit);
        $this->assertSame($session->id, $audit->subject_id);
        $this->assertTrue(json_decode($audit->metadata, true)['deleted_at_video_service']);
        $this->assertStringNotContainsString(self::RECORDING, json_encode($audit));
    }

    #[Test]
    public function a_failed_refusal_delete_asks_daily_to_deliver_again(): void
    {
        $session = $this->finished(consent: false);
        $this->realDaily();
        Http::fake(['api.daily.co/v1/recordings/'.self::RECORDING => Http::sequence()->push(['error' => 'server-error'], 500)->push(['deleted' => true])]);

        $this->deliver($this->ready($session->provider_room_name))->assertStatus(503);
        $this->deliver($this->ready($session->provider_room_name))->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.daily.co/v1/recordings/'.self::RECORDING
            && $r->hasHeader('Authorization', 'Bearer '.self::API_KEY));
        $this->assertSame(0, DB::table('session_recordings')->count());
        $this->assertSame([false, true], DB::table('audit_logs')->where('action', 'telehealth.recording_refused')->orderBy('occurred_at')->orderBy('id')
            ->pluck('metadata')->map(fn ($m) => json_decode($m, true)['deleted_at_video_service'])->all());
    }

    #[Test]
    public function unknown_rooms_unhandled_events_and_malformed_payloads_are_acknowledged_and_ignored(): void
    {
        $session = $this->finished();

        $this->deliver($this->ready('NotOurRoom1234567890'))->assertOk();
        $this->deliver($this->event('meeting.started', ['room' => $session->provider_room_name, 'meeting_id' => 'm-1', 'start_ts' => 1]))->assertOk();
        $this->deliver($this->ready($session->provider_room_name, '../../etc/passwd'))->assertOk();
        $this->deliver($this->event('recording.ready-to-download', ['recording_id' => self::RECORDING, 'room_name' => ['x']]))->assertOk();
        $this->deliver('not json')->assertOk();

        $this->assertSame(0, DB::table('session_recordings')->count());
        $this->assertSame([], $this->daily->callsOf('recordings.delete'));
    }

    #[Test]
    public function a_recording_error_is_audited_and_logged_without_dailys_message(): void
    {
        Log::spy();
        $session = $this->finished();

        $this->deliver($this->event('recording.error', [
            'action' => 'cloud-recording-error', 'error_msg' => 'Participant Alice Alpha <alice@example.com> dropped', 'instance_id' => 'i-1',
            'room_name' => $session->provider_room_name, 'timestamp' => 1759392000,
        ]))->assertOk();

        $audit = DB::table('audit_logs')->where('action', 'telehealth.recording_failed')->first();
        $this->assertNotNull($audit);
        $this->assertSame($session->organization_id, $audit->organization_id);
        $this->assertSame(['action' => 'cloud-recording-error'], json_decode($audit->metadata, true));
        $this->assertStringNotContainsString('alice@example.com', json_encode($audit));
        Log::shouldHaveReceived('warning')->with('Telehealth: the video service reported a recording error', ['action' => 'cloud-recording-error']);
        $this->assertSame(0, DB::table('session_recordings')->count());
    }

    // ── the database still guards consent ────────────────────────────────────

    #[Test]
    public function the_database_refuses_a_daily_recording_without_consent_and_rows_that_are_neither_or_both(): void
    {
        $unconsented = $this->sessionAt('2026-10-06 10:00:00');
        $consented = $this->consented($this->sessionAt('2026-10-07 10:00:00'));
        $row = fn (TelehealthSession $s, array $columns) => [
            'id' => (string) Str::uuid7(), 'organization_id' => $s->organization_id, 'record_environment' => 'live', 'telehealth_session_id' => $s->id,
            'consented' => true, 'mime' => 'video/mp4', 'created_at' => now(), 'updated_at' => now(),
        ] + $columns;

        foreach ([
            [$unconsented, ['provider_key' => 'daily', 'provider_recording_id' => 'r-1'], 'session_recordings_consent_fk'],
            [$consented, [], 'session_recordings_source_check'],
            [$consented, ['provider_key' => 'daily', 'provider_recording_id' => 'r-2', 'storage_path' => 'x', 'sha256' => str_repeat('a', 64), 'size_bytes' => 5], 'session_recordings_source_check'],
            [$consented, ['provider_recording_id' => 'r-3'], 'session_recordings_provider_check'],
            [$consented, ['storage_path' => 'x'], 'session_recordings_file_check'],
            [$consented, ['provider_key' => 'daily', 'provider_recording_id' => 'r-4', 'size_bytes' => 0], 'session_recordings_size_check'],
        ] as [$session, $columns, $constraint]) {
            try {
                DB::transaction(fn () => DB::table('session_recordings')->insert($row($session, $columns)));
                $this->fail("A row violating {$constraint} was stored.");
            } catch (QueryException $e) {
                $this->assertStringContainsString($constraint, $e->getMessage());
            }
        }

        DB::table('session_recordings')->insert($row($consented, ['provider_key' => 'daily', 'provider_recording_id' => 'r-5']));
        try {
            DB::transaction(fn () => DB::table('session_recordings')->insert($row($consented, ['provider_key' => 'daily', 'provider_recording_id' => 'r-5'])));
            $this->fail('A Daily recording was stored twice.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('session_recordings_provider_recording_id_unique', $e->getMessage());
        }
    }

    // ── download ─────────────────────────────────────────────────────────────

    #[Test]
    public function a_daily_recording_downloads_through_a_fresh_short_link_minted_per_request_and_audited(): void
    {
        $session = $this->finished();
        $this->deliver($this->ready($session->provider_room_name))->assertOk();
        $recordingId = (string) DB::table('session_recordings')->value('id');
        $download = $this->url('app.telehealth.recordings.download', ['session' => $session->id, 'recording' => $recordingId]);

        // No clinical access: refused before any link is minted.
        $this->revokeFromRole($this->organization, 'practice_manager', 'telehealth.notes');
        $this->as($this->addStaff($this->organization, 'practice_manager'))->get($download)->assertForbidden();
        $this->as($this->drB)->get($download)->assertNotFound();
        $this->assertSame([], $this->daily->callsOf('recordings.access-link'));

        $response = $this->as($this->drA)->get($download)->assertRedirect();
        $this->assertStringStartsWith('https://wellnest-dev.daily.co/recordings/'.self::RECORDING.'.mp4?expires=', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame([['id' => self::RECORDING, 'valid_for_secs' => 900]], $this->daily->callsOf('recordings.access-link'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'telehealth.recording_downloaded')->where('subject_id', $recordingId)->count());
        $this->assertStringNotContainsString('expires=', json_encode([DB::table('session_recordings')->get(), DB::table('audit_logs')->get()]), 'the link is never stored');

        // With the real client: the exact access-link request, and a failure is a message, not a 500.
        $this->realDaily();
        Http::fake(['api.daily.co/v1/recordings/'.self::RECORDING.'/access-link*' => Http::sequence()
            ->push(['download_link' => 'https://daily-recordings.s3.amazonaws.com/x.mp4?X-Amz-Signature=abc', 'expires' => 1759393000, 'storage_provider' => 'aws'])
            ->push(['error' => 'server-error'], 500)]);
        $this->get($download)->assertRedirect('https://daily-recordings.s3.amazonaws.com/x.mp4?X-Amz-Signature=abc');
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === 'https://api.daily.co/v1/recordings/'.self::RECORDING.'/access-link?valid_for_secs=900');
        $this->from($this->url('app.telehealth.show', ['session' => $session->id]))->get($download)
            ->assertRedirect($this->url('app.telehealth.show', ['session' => $session->id]))->assertSessionHas('error');
    }
}
