<?php

namespace App\Domain\Clients\Listeners;

use App\Domain\Clients\Events\ClientCreated;
use App\Domain\Clients\Timeline;

/** The first entry of every client's timeline. Administrative: visible to anyone who can view the client. */
final class RecordClientCreatedOnTimeline
{
    public function __construct(private readonly Timeline $timeline) {}

    public function handle(ClientCreated $event): void
    {
        $this->timeline->record(
            $event->client,
            Timeline::ADMINISTRATIVE,
            'client.created',
            'Client record created',
            subject: $event->client,
            actorUserId: $event->actorUserId,
            occurredAt: $event->client->created_at,
        );
    }
}
