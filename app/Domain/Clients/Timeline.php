<?php

namespace App\Domain\Clients;

use App\Models\Client;
use App\Models\TimelineEntry;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The single writer of the client timeline projection. Modules call it from
 * their event listeners. Entries are insert-only; the reader filters by
 * category against the viewer's permissions, so keep clinical detail out of
 * non-clinical categories and keep summaries free of clinical content.
 */
final class Timeline
{
    public const ADMINISTRATIVE = 'administrative';

    public const SCHEDULING = 'scheduling';

    public const CLINICAL = 'clinical';

    public const FINANCIAL = 'financial';

    public const COMMUNICATION = 'communication';

    public const PROGRAM = 'program';

    public const DOCUMENT = 'document';

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        Client $client,
        string $category,
        string $type,
        string $summary,
        ?Model $subject = null,
        ?string $actorUserId = null,
        ?DateTimeInterface $occurredAt = null,
        array $metadata = [],
    ): TimelineEntry {
        $entry = new TimelineEntry;
        $entry->forceFill([
            'organization_id' => $client->organization_id,
            'client_id' => $client->id,
            'record_environment' => $client->record_environment,
            'occurred_at' => $occurredAt ?? now(),
            'category' => $category,
            'type' => $type,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'summary' => Str::limit($summary, 297),
            'actor_user_id' => $actorUserId,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ])->save();

        return $entry;
    }
}
