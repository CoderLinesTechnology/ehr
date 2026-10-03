<?php

namespace Tests\Feature\Telehealth;

use App\Domain\Scheduling\Modality;
use App\Domain\Telehealth\AttachRecording;
use App\Domain\Telehealth\RecordConsent;
use App\Domain\Telehealth\SetMeetingLink;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\OrganizationMembership;
use App\Models\SessionRecording;
use App\Models\TelehealthSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Scheduling\Http\SchedulingHttpTestCase;

/**
 * Telehealth tests run on the scheduling HTTP fixture: organization A (Accra, clock frozen at Fri 2026-10-02
 * 08:00 UTC) with two clinicians, drA and drB, one client each, a telehealth-capable service; organization B
 * is a second tenant. Booking a telehealth appointment creates its session through the scheduling listener.
 */
abstract class TelehealthTestCase extends SchedulingHttpTestCase
{
    protected const LINK = 'https://acme.zoom.us/j/84311220012?pwd=Secret123';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** Book a telehealth appointment and return its session. */
    protected function sessionAt(string $startsUtc, ?OrganizationMembership $clinician = null, ?Client $client = null, ?string $link = self::LINK): TelehealthSession
    {
        $appointment = $this->book($clinician ?? $this->drA, $client ?? $this->clientA, $startsUtc, Modality::Telehealth);
        $session = $this->sessionOf($appointment);

        if ($link !== null) {
            app(SetMeetingLink::class)($session, $link, $this->actor);
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
}
