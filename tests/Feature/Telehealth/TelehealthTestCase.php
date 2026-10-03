<?php

namespace Tests\Feature\Telehealth;

use App\Domain\Scheduling\Modality;
use App\Domain\Telehealth\AttachRecording;
use App\Domain\Telehealth\Daily\FakeDailyClient;
use App\Domain\Telehealth\PrepareRoom;
use App\Domain\Telehealth\RecordConsent;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\OrganizationMembership;
use App\Models\SessionRecording;
use App\Models\TelehealthSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Scheduling\Http\SchedulingHttpTestCase;

/**
 * Telehealth tests run on the scheduling HTTP fixture: organization A (Accra, clock frozen at Fri 2026-10-02
 * 08:00 UTC) with two clinicians, drA and drB, one client each, a telehealth-capable service; organization B
 * is a second tenant. Booking a telehealth appointment creates its session through the scheduling listener.
 *
 * Video runs on the simulated Daily client ($this->daily records every call) and any real HTTP request fails the
 * test (Http::preventStrayRequests); tests of the real client switch to it with realDaily() and Http::fake().
 */
abstract class TelehealthTestCase extends SchedulingHttpTestCase
{
    protected const API_KEY = 'dk_test_0123456789abcdef';

    /** base64 of 32 bytes: what an operator generates for DAILY_WEBHOOK_SECRET. */
    protected const WEBHOOK_SECRET = 'q5c3Yy6V0b6S0xq4v3l3m8bJw0q2r0c9b8h7k6j5g4E=';

    protected FakeDailyClient $daily;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();

        config(['services.daily' => [
            'api_key' => null, 'webhook_secret' => null, 'geo' => null, 'api_base' => 'https://api.daily.co/v1',
            'connect_timeout' => 3, 'timeout' => 8, 'fake' => true,
        ]]);
        $this->daily = app(FakeDailyClient::class);
    }

    /** No key, no fake: video is not set up. */
    protected function videoNotConfigured(): void
    {
        config(['services.daily.fake' => false, 'services.daily.api_key' => null]);
    }

    /** The real HTTP client (pair with Http::fake()). */
    protected function realDaily(): void
    {
        config(['services.daily.fake' => false, 'services.daily.api_key' => self::API_KEY]);
    }

    /** Book a telehealth appointment and return its session; with $room its (simulated) Daily room is prepared. */
    protected function sessionAt(string $startsUtc, ?OrganizationMembership $clinician = null, ?Client $client = null, bool $room = true): TelehealthSession
    {
        $appointment = $this->book($clinician ?? $this->drA, $client ?? $this->clientA, $startsUtc, Modality::Telehealth);
        $session = $this->sessionOf($appointment);

        if ($room) {
            app(PrepareRoom::class)($session);
            $session->refresh();
        }

        return $session;
    }

    protected function sessionOf(Appointment $appointment): TelehealthSession
    {
        return TelehealthSession::query()->where('appointment_id', $appointment->id)->firstOrFail();
    }

    protected function enableRecording(): void
    {
        $this->setting('telehealth.recording_enabled', true);
    }

    protected function consented(TelehealthSession $session): TelehealthSession
    {
        $this->enableRecording();

        return app(RecordConsent::class)($session, true, $this->actor);
    }

    protected function recordingFor(TelehealthSession $session): SessionRecording
    {
        $this->consented($session);

        return app(AttachRecording::class)($session, $this->wav(), 60, $this->actor);
    }

    /** A real (silent) WAV: the type is decided by sniffing bytes, so the header must be genuine. */
    protected function wav(string $name = 'session.wav'): UploadedFile
    {
        $samples = str_repeat("\x00\x00", 800);
        $bytes = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($samples)).$samples;

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    protected function joinUrl(TelehealthSession $session): string
    {
        return $this->url('app.telehealth.join', ['session' => $session->id]);
    }

    protected function callUrl(TelehealthSession $session): string
    {
        return $this->url('app.telehealth.call', ['session' => $session->id]);
    }

    /** @return list<string> the operations the simulated Daily client received */
    protected function dailyOperations(): array
    {
        return array_column($this->daily->calls, 0);
    }
}
