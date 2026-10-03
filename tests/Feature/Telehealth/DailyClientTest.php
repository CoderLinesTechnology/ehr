<?php

namespace Tests\Feature\Telehealth;

use App\Domain\Scheduling\Modality;
use App\Domain\Shared\DomainException;
use App\Domain\Telehealth\Daily\DailyClient;
use App\Domain\Telehealth\Daily\DailyException;
use App\Domain\Telehealth\EndSession;
use App\Domain\Telehealth\IssueCallPass;
use App\Domain\Telehealth\OpenSession;
use App\Domain\Telehealth\PrepareRoom;
use App\Domain\Telehealth\RecordConsent;
use App\Domain\Telehealth\SessionStatus;
use App\Models\TelehealthSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

/** The real Daily client, against Http::fake: exact request shapes, auth, error mapping, and what is (not) logged. */
class DailyClientTest extends TelehealthTestCase
{
    private const ROOM = 'Hx7kQ2pL9mN4vB8cZ1aW';

    protected function setUp(): void
    {
        parent::setUp();
        $this->realDaily();
    }

    private function roomCreated(string $name = self::ROOM): array
    {
        return ['id' => 'b3c1-room', 'name' => $name, 'api_created' => true, 'privacy' => 'private', 'url' => "https://wellnest.daily.co/{$name}", 'created_at' => '2026-10-02T08:00:00.000Z', 'config' => []];
    }

    /** @return list<Request> */
    private function sent(): array
    {
        return array_map(fn (array $pair) => $pair[0], Http::recorded()->all());
    }

    #[Test]
    public function a_room_is_created_private_with_knocking_and_no_custom_name_and_cannot_record_by_itself(): void
    {
        config(['services.daily.geo' => 'eu-west-2']);
        Http::fake(['api.daily.co/v1/rooms' => Http::response($this->roomCreated())]);

        $session = $this->sessionAt('2026-10-02 08:30:00');

        $requests = $this->sent();
        $this->assertCount(1, $requests);
        $request = $requests[0];
        $this->assertSame('POST', $request->method());
        $this->assertSame('https://api.daily.co/v1/rooms', $request->url());
        $this->assertSame(['Bearer '.self::API_KEY], $request->header('Authorization'));
        $body = $request->data();
        $this->assertSame(['privacy', 'properties'], array_keys($body), 'no `name`: Daily generates it (HIPAA mode refuses custom names)');
        $this->assertSame('private', $body['privacy']);
        $this->assertEquals([
            'nbf' => CarbonImmutable::parse('2026-10-02 08:15:00', 'UTC')->getTimestamp(),   // join opens 15 minutes early
            'exp' => CarbonImmutable::parse('2026-10-02 11:30:00', 'UTC')->getTimestamp(),   // two hours after the end
            'eject_at_room_exp' => true,
            'enable_knocking' => true,
            'enable_prejoin_ui' => true,
            'enable_chat' => false,
            'geo' => 'eu-west-2',
        ], $body['properties']);

        $this->assertSame(self::ROOM, $session->provider_room_name);
        $this->assertSame('https://wellnest.daily.co/'.self::ROOM, $session->join_url);
        $this->assertNotSame('https://wellnest.daily.co/'.self::ROOM, DB::table('telehealth_sessions')->where('id', $session->id)->value('join_url'), 'the room link is encrypted at rest');

        // Prepared again with the same window: no outbound call.
        app(PrepareRoom::class)($session);
        $this->assertCount(1, $this->sent());
    }

    #[Test]
    public function an_unknown_media_region_is_not_sent_and_a_changed_window_updates_the_room(): void
    {
        config(['services.daily.geo' => 'mars-north-1']);
        Http::fake([
            'api.daily.co/v1/rooms' => Http::response($this->roomCreated()),
            'api.daily.co/v1/rooms/'.self::ROOM => Http::response(['name' => self::ROOM]),
        ]);
        $session = $this->sessionAt('2026-10-02 08:30:00');
        $this->assertArrayNotHasKey('geo', $this->sent()[0]->data()['properties']);

        $this->setting('telehealth.join_early_minutes', 60);
        app(PrepareRoom::class)($session);

        $update = $this->sent()[1];
        $this->assertSame('POST', $update->method());
        $this->assertSame('https://api.daily.co/v1/rooms/'.self::ROOM, $update->url());
        $this->assertSame(CarbonImmutable::parse('2026-10-02 07:30:00', 'UTC')->getTimestamp(), $update->data()['properties']['nbf']);
        $this->assertSame('private', $update->data()['privacy']);
        $this->assertEquals(CarbonImmutable::parse('2026-10-02 07:30:00', 'UTC'), $session->refresh()->provider_room_nbf);
    }

    #[Test]
    public function a_room_that_is_gone_at_daily_is_replaced(): void
    {
        Http::fake(['api.daily.co/v1/rooms' => Http::sequence()->push($this->roomCreated())->push($this->roomCreated('Zz9Yy8Xx7Ww6Vv5Uu4Tt'))]);
        $session = $this->sessionAt('2026-10-02 08:30:00');

        // Rescheduled data or a hand-deleted room: the update answers 404, so a new room is created and stored.
        Http::fake(['api.daily.co/v1/rooms/'.self::ROOM => Http::response(['error' => 'not-found', 'info' => 'room not found'], 404)]);
        $this->setting('telehealth.join_early_minutes', 30);
        $room = app(PrepareRoom::class)($session);

        $this->assertSame('Zz9Yy8Xx7Ww6Vv5Uu4Tt', $room?->name);
        $this->assertSame('Zz9Yy8Xx7Ww6Vv5Uu4Tt', $session->refresh()->provider_room_name);
    }

    #[Test]
    public function every_pass_is_bound_to_the_room_and_expires_and_records_only_with_setting_consent_and_clinical_access(): void
    {
        $service = $this->service(['name' => 'Long Group', 'duration_minutes' => 360], [$this->drA]);
        Http::fake([
            'api.daily.co/v1/rooms' => Http::response($this->roomCreated()),
            'api.daily.co/v1/meeting-tokens' => Http::response(['token' => 'eyJhbGciOi.secret-token.sig']),
        ]);
        $appointment = $this->book($this->drA, $this->clientA, '2026-10-02 08:05:00', Modality::Telehealth, $service);
        $session = $this->sessionOf($appointment);
        app(OpenSession::class)($session, $this->actor);

        $pass = app(IssueCallPass::class)($session, $this->drA);

        $token = collect($this->sent())->first(fn (Request $r) => $r->url() === 'https://api.daily.co/v1/meeting-tokens');
        $this->assertNotNull($token);
        $this->assertSame(['Bearer '.self::API_KEY], $token->header('Authorization'));
        $this->assertSame(['properties'], array_keys($token->data()));
        $this->assertEquals([
            'room_name' => self::ROOM,
            // A 6-hour session: the room lasts until 16:05, but no pass outlives four hours.
            'exp' => CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC')->getTimestamp(),
            'user_name' => $this->drA->professionalName(),
            'user_id' => $this->drA->id,
            'is_owner' => true,
            'enable_prejoin_ui' => false,
        ], $token->data()['properties']);
        $this->assertSame('https://wellnest.daily.co/'.self::ROOM.'?t='.rawurlencode('eyJhbGciOi.secret-token.sig'), $pass->frameUrl);

        // Recording on for the organization and consent recorded: the session's clinician may record.
        $this->consented($session->refresh());
        app(IssueCallPass::class)($session->refresh(), $this->drA);
        $properties = collect($this->sent())->filter(fn (Request $r) => str_ends_with($r->url(), '/meeting-tokens'))->last()->data()['properties'];
        $this->assertSame('cloud', $properties['enable_recording']);
        $this->assertTrue($properties['enable_recording_ui']);
        $this->assertSame(self::ROOM, $properties['room_name']);
        $this->assertIsInt($properties['exp']);

        // Withdrawn: the next pass cannot record (and Daily is asked to stop a recording in progress).
        Http::fake(['api.daily.co/v1/rooms/'.self::ROOM.'/recordings/stop' => Http::response(['status' => 'stopped'])]);
        app(RecordConsent::class)($session->refresh(), false, $this->actor);
        $stop = collect($this->sent())->first(fn (Request $r) => str_ends_with($r->url(), '/recordings/stop'));
        $this->assertNotNull($stop);
        $this->assertSame('POST', $stop->method());
        $this->assertSame('https://api.daily.co/v1/rooms/'.self::ROOM.'/recordings/stop', $stop->url());
        app(IssueCallPass::class)($session->refresh(), $this->drA);
        $properties = collect($this->sent())->filter(fn (Request $r) => str_ends_with($r->url(), '/meeting-tokens'))->last()->data()['properties'];
        $this->assertArrayNotHasKey('enable_recording', $properties);
        $this->assertArrayNotHasKey('enable_recording_ui', $properties);
    }

    #[Test]
    public function a_daily_error_becomes_a_safe_refusal_and_only_its_type_is_logged(): void
    {
        Log::spy();
        Http::fake(['api.daily.co/v1/rooms' => Http::response(['error' => 'invalid-request-error', 'info' => 'details nobody should see'], 400)]);
        $session = $this->sessionAt('2026-10-02 08:30:00', room: false);

        try {
            app(PrepareRoom::class)($session);
            $this->fail('A Daily error was not turned into a refusal.');
        } catch (DomainException $e) {
            $this->assertSame('video_unavailable', $e->errorCode());
            $this->assertStringNotContainsString('details nobody', $e->userMessage());
        }

        Log::shouldHaveReceived('warning')->once()->with('Daily API error', ['operation' => 'rooms.create', 'error' => 'invalid-request-error', 'status' => 400]);
        $this->assertNull($session->refresh()->provider_room_name);

        // And the page still renders, with a notice and no join button.
        $this->as($this->drA)->get($this->joinUrl($session))->assertOk()
            ->assertSee('The video service could not be reached')->assertDontSee('class="tj-join-form"', false);
    }

    #[Test]
    public function an_unreachable_daily_is_a_safe_refusal_too(): void
    {
        Http::fake(['*' => Http::failedConnection('timed out')]);
        $session = $this->sessionAt('2026-10-02 08:30:00', room: false);

        $this->as($this->drA)->post($this->url('app.telehealth.start', ['session' => $session->id]))->assertRedirect()->assertSessionHas('error');
        $this->assertSame(SessionStatus::Scheduled, $session->refresh()->status, 'no video, no start');
    }

    #[Test]
    public function the_client_refuses_tokens_without_a_room_or_expiry_and_odd_names_without_any_request(): void
    {
        Http::fake();
        $client = app(DailyClient::class);

        foreach ([[], ['room_name' => self::ROOM], ['exp' => 123]] as $properties) {
            try {
                $client->createMeetingToken($properties);
                $this->fail('A token without room_name and exp was requested.');
            } catch (DailyException $e) {
                $this->assertSame('invalid-request-error', $e->errorType);
            }
        }
        foreach (['../meeting-tokens', 'a/b', 'room?x=1', ''] as $name) {
            try {
                $client->deleteRoom($name);
                $this->fail("[{$name}] reached the URL path.");
            } catch (DailyException) {
                $this->addToAssertionCount(1);
            }
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function ending_deletes_the_room_after_commit_and_a_failed_delete_is_logged_not_thrown(): void
    {
        Log::spy();
        Http::fake([
            'api.daily.co/v1/rooms' => Http::response($this->roomCreated()),
            'api.daily.co/v1/rooms/'.self::ROOM => Http::response(['error' => 'server-error'], 500),
        ]);
        $session = $this->sessionAt('2026-10-02 08:05:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:06:00', 'UTC'));
        app(OpenSession::class)($session, $this->actor);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:50:00', 'UTC'));

        app(EndSession::class)($session, $this->actor);

        $delete = collect($this->sent())->first(fn (Request $r) => $r->method() === 'DELETE');
        $this->assertNotNull($delete);
        $this->assertSame('https://api.daily.co/v1/rooms/'.self::ROOM, $delete->url());
        $this->assertSame(SessionStatus::Completed, $session->refresh()->status);
        $this->assertSame(self::ROOM, $session->provider_room_name, 'the name stays: the recording webhook arrives later');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_contains($message, 'could not be deleted') && $context['error'] === 'server-error');
    }

    #[Test]
    public function while_daily_is_unreachable_one_request_tries_to_release_one_room_not_every_room(): void
    {
        Http::fake(['api.daily.co/v1/rooms' => Http::sequence()->push($this->roomCreated('Room1aaaaaaaaaaaaaaa'))->push($this->roomCreated('Room2bbbbbbbbbbbbbbb'))]);
        $first = $this->sessionAt('2026-10-06 10:00:00');
        $second = $this->sessionAt('2026-10-07 10:00:00');
        Http::fake(['api.daily.co/v1/rooms/*' => Http::failedConnection()]);

        // Both appointments were edited to in person; the sessions list's reconcile retires both in one request.
        DB::table('appointments')->whereIn('id', [$first->appointment_id, $second->appointment_id])->update(['modality' => 'in_person', 'location_id' => $this->accra->id]);
        $this->as($this->drA)->get($this->url('app.telehealth.index'))->assertOk();

        $this->assertSame(SessionStatus::Cancelled, $first->refresh()->status);
        $this->assertSame(SessionStatus::Cancelled, $second->refresh()->status);
        $this->assertCount(1, collect($this->sent())->filter(fn (Request $r) => $r->method() === 'DELETE'), 'the second release is skipped, not timed out');
    }

    #[Test]
    public function the_webhook_registration_sends_our_secret_both_recording_events_and_exponential_retries(): void
    {
        config(['services.daily.webhook_secret' => self::WEBHOOK_SECRET]);
        Http::fake([
            'api.daily.co/v1/webhooks' => Http::response(['uuid' => 'wh-0001', 'state' => 'ACTIVE']),
            'api.daily.co/v1/webhooks/wh-0001' => Http::response(['uuid' => 'wh-0001', 'state' => 'ACTIVE']),
        ]);

        $this->artisan('telehealth:daily-webhook', ['url' => 'https://app.example.com/webhooks/daily'])
            ->expectsOutputToContain('wh-0001')->assertSuccessful();
        $this->artisan('telehealth:daily-webhook', ['url' => 'https://app.example.com/webhooks/daily', '--uuid' => 'wh-0001'])->assertSuccessful();

        [$create, $update] = $this->sent();
        $this->assertSame('https://api.daily.co/v1/webhooks', $create->url());
        $this->assertSame('https://api.daily.co/v1/webhooks/wh-0001', $update->url());
        foreach ([$create, $update] as $request) {
            $this->assertSame('POST', $request->method());
            $this->assertSame(['Bearer '.self::API_KEY], $request->header('Authorization'));
            $this->assertEquals([
                'url' => 'https://app.example.com/webhooks/daily',
                'hmac' => self::WEBHOOK_SECRET,
                'eventTypes' => ['recording.ready-to-download', 'recording.error'],
                'retryType' => 'exponential',
            ], $request->data());
        }

        // Not https, or no usable secret: refused before any request.
        $this->artisan('telehealth:daily-webhook', ['url' => 'http://app.example.com/webhooks/daily'])->assertFailed();
        config(['services.daily.webhook_secret' => 'REPLACE_ME']);
        $this->artisan('telehealth:daily-webhook', ['url' => 'https://app.example.com/webhooks/daily'])->assertFailed();
        $this->assertCount(2, $this->sent());
    }

    #[Test]
    public function a_session_without_a_stored_room_yet_has_none_in_the_database(): void
    {
        Http::fake();
        $session = $this->sessionAt('2026-10-06 10:00:00', room: false);

        $this->assertNull(TelehealthSession::query()->whereKey($session->id)->value('provider_room_name'));
        Http::assertNothingSent();
    }
}
