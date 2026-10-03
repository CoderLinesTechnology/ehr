<?php

namespace App\Domain\Telehealth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\SessionTranscript;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** A clinician confirms they read the transcript: draft → reviewed, with who and when. The human review the register requires (row 12). */
final class ReviewTranscript
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(SessionTranscript $transcript, User $reviewer): SessionTranscript
    {
        TenantGuard::assertOwned($this->tenant->organizationOrFail()->id, $transcript);

        return DB::transaction(function () use ($transcript, $reviewer) {
            /** @var SessionTranscript $locked */
            $locked = SessionTranscript::query()->lockForUpdate()->findOrFail($transcript->id);

            if ($locked->status === TranscriptStatus::Reviewed) {
                throw new DomainException('This transcript has already been reviewed.', 'transcript_reviewed');
            }

            $locked->forceFill([
                'status' => TranscriptStatus::Reviewed,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            $this->audit->record(
                'telehealth.transcript_reviewed',
                subject: $locked,
                before: ['status' => 'draft'],
                after: ['status' => 'reviewed'],
                metadata: ['source' => $locked->source->value],
                summary: 'A telehealth transcript was reviewed by a clinician',
            );

            $transcript->setRawAttributes($locked->getAttributes(), true);

            return $transcript;
        });
    }
}
