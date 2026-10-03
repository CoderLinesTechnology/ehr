<?php

namespace App\Domain\Telehealth;

use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Shared\DomainException;
use App\Domain\Telehealth\Providers\MeetingProvider;
use App\Domain\Telehealth\Providers\ProviderRegistry;
use App\Domain\Telehealth\Providers\RoomSpec;
use App\Domain\Telehealth\Providers\VideoRoom;
use App\Domain\Telehealth\Providers\VideoServiceException;
use App\Domain\Tenancy\TenantContext;
use App\Models\TelehealthSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Makes sure an open session has its video room, with the window the session needs (join opens N minutes before
 * the start; the room expires two hours after the booked end). Idempotent: called when the join page is rendered
 * and before every call pass, it makes no outbound call while the stored room already has the right window.
 *
 * Returns null — and calls nobody — for demo sessions (no external delivery for demo data), closed sessions and
 * installations where the video service is not set up. A vendor failure is a DomainException('video_unavailable')
 * with a safe message, never a 500.
 *
 * Outbound calls happen outside any transaction or row lock. The new room is stored with a compare-and-set
 * (`WHERE provider_room_name IS NULL`): when two requests race, the loser gives its room back (best effort) and
 * uses the winner's.
 */
final class PrepareRoom
{
    /** The room stays joinable this long after the booked end (an overrunning session), then Daily ejects everyone. */
    public const GRACE_AFTER_END_MINUTES = 120;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProviderRegistry $providers,
        private readonly TelehealthSettings $settings,
    ) {}

    public function __invoke(TelehealthSession $session): ?VideoRoom
    {
        $organization = $this->tenant->organizationOrFail();
        TenantGuard::assertOwned($organization->id, $session);

        if ($session->isDemo() || ! $session->status->isOpen()) {
            return null;
        }
        $provider = $this->providers->get($session->provider_key);
        if (! $provider->status()->usable()) {
            return null;
        }

        [$notBefore, $expiresAt] = self::window($session->starts_at, $session->ends_at, $this->settings->joinEarlyMinutes($organization));
        if ($expiresAt->lessThanOrEqualTo(now())) {
            throw new DomainException('The video room of this session has closed.', 'video_closed');
        }
        $spec = new RoomSpec($notBefore, $expiresAt);

        $stored = $this->stored($session->id);
        if ($stored !== null) {
            $room = $this->keepInStep($provider, $session, $stored, $spec);
            if ($room !== null) {
                return $room;
            }
        }

        try {
            $room = $provider->createRoom($spec);
        } catch (VideoServiceException) {
            throw self::unavailable();
        }

        if (! $this->claim($session->id, $room, $spec)) {
            // Another request stored its room first (or the session closed meanwhile): give ours back, use theirs.
            $this->deleteQuietly($provider, $room->name);
            $session->refresh();
            $winner = $this->stored($session->id);

            return $winner?->room ?? throw self::unavailable();
        }

        $session->refresh();

        return $room;
    }

    /**
     * [nbf, exp] of a session's room: from the join window's opening (capped like OpenSession's window) until
     * GRACE_AFTER_END_MINUTES after the end.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function window(\DateTimeInterface $startsAt, \DateTimeInterface $endsAt, int $earlyMinutes): array
    {
        $early = max(0, min($earlyMinutes, TelehealthSettings::MAX_EARLY_MINUTES));

        return [
            CarbonImmutable::instance($startsAt)->utc()->subMinutes($early),
            CarbonImmutable::instance($endsAt)->utc()->addMinutes(self::GRACE_AFTER_END_MINUTES),
        ];
    }

    public static function unavailable(): DomainException
    {
        return new DomainException('The video service could not be reached. Try again in a moment.', 'video_unavailable');
    }

    /** The stored room with the window Daily has, when it already matches; otherwise updated (or forgotten when gone). */
    private function keepInStep(MeetingProvider $provider, TelehealthSession $session, StoredRoom $stored, RoomSpec $spec): ?VideoRoom
    {
        if ($stored->notBefore?->getTimestamp() === $spec->notBefore->getTimestamp()
            && $stored->expiresAt?->getTimestamp() === $spec->expiresAt->getTimestamp()) {
            return $stored->room;
        }

        try {
            $provider->updateRoom($stored->room->name, $spec);
        } catch (VideoServiceException $e) {
            if (! $e->notFound()) {
                throw self::unavailable();
            }

            // Gone at the vendor (deleted by hand, or expired and purged): forget it; the caller creates a new one.
            TelehealthSession::query()->whereKey($session->id)->where('provider_room_name', $stored->room->name)->update([
                'provider_room_name' => null, 'join_url' => null, 'provider_room_nbf' => null, 'provider_room_exp' => null,
            ]);

            return null;
        }

        TelehealthSession::query()->whereKey($session->id)->where('provider_room_name', $stored->room->name)->update([
            'provider_room_nbf' => self::bind($spec->notBefore),
            'provider_room_exp' => self::bind($spec->expiresAt),
        ]);
        $session->refresh();

        return $stored->room;
    }

    /** Compare-and-set: store the room only if the session still has none and is still open. */
    private function claim(string $sessionId, VideoRoom $room, RoomSpec $spec): bool
    {
        try {
            // A savepoint, so a lost unique race does not abort a surrounding transaction.
            return DB::transaction(fn () => TelehealthSession::query()
                ->whereKey($sessionId)
                ->whereNull('provider_room_name')
                ->whereIn('status', SessionStatus::OPEN)
                ->update([
                    'provider_room_name' => $room->name,
                    'join_url' => Crypt::encryptString($room->url),   // the model's `encrypted` cast, applied by hand for a builder update
                    'provider_room_nbf' => self::bind($spec->notBefore),
                    'provider_room_exp' => self::bind($spec->expiresAt),
                ])) === 1;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function stored(string $sessionId): ?StoredRoom
    {
        $row = TelehealthSession::query()->whereKey($sessionId)
            ->first(['id', 'organization_id', 'provider_room_name', 'join_url', 'provider_room_nbf', 'provider_room_exp']);
        if ($row === null || $row->provider_room_name === null || ! is_string($row->join_url) || $row->join_url === '') {
            return null;
        }

        return new StoredRoom(new VideoRoom($row->provider_room_name, $row->join_url), $row->provider_room_nbf, $row->provider_room_exp);
    }

    private function deleteQuietly(MeetingProvider $provider, string $name): void
    {
        try {
            $provider->deleteRoom($name);
        } catch (VideoServiceException $e) {
            Log::warning('Telehealth: a surplus video room could not be deleted; it expires by itself', ['error' => $e->errorType]);
        }
    }

    /** Builder updates bypass casts and drop offsets: bind instants as explicit UTC strings. */
    private static function bind(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d H:i:sP');
    }
}
