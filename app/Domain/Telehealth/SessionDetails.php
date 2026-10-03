<?php

namespace App\Domain\Telehealth;

use App\Models\SessionRecording;
use App\Models\SessionTranscript;
use Carbon\CarbonImmutable;

/**
 * Everything the join and completed pages show about one session, loaded once. Clinical content (notes,
 * recordings, transcripts) is present only when the viewer may see it ($clinical).
 */
final readonly class SessionDetails
{
    /**
     * @param  list<SessionRecording>  $recordings
     * @param  list<SessionTranscript>  $transcripts
     * @param  array{id: string, startsAt: CarbonImmutable, timezone: string}|null  $nextAppointment
     */
    public function __construct(
        public string $clientName,
        public string $clientNumber,
        public string $clientId,
        public string $serviceId,
        public string $serviceName,
        public string $clinicianId,
        public string $clinicianName,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $timezone,
        public string $appointmentId,
        public string $vendor,
        public bool $clinical,
        public ?string $notes,
        public int $noteVersion,
        public array $recordings,
        public array $transcripts,
        public ?array $nextAppointment,
        public ?int $durationMinutes,
        public int $joinEarlyMinutes,
        public bool $joinWindowOpen,
    ) {}

    /** "1h 00m" / "45m". */
    public function durationLabel(): string
    {
        $minutes = $this->durationMinutes ?? (int) $this->startsAt->diffInMinutes($this->endsAt);

        return intdiv($minutes, 60) > 0 ? intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m' : $minutes.'m';
    }
}
