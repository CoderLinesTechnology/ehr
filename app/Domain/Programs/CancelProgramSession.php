<?php

namespace App\Domain\Programs;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Domain\Shared\DomainException;
use App\Models\Program;
use App\Models\ProgramSession;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a program session that has not started (it stays on record, marked cancelled, and leaves the calendar).
 * Needs `programs.manage`. A session that has started cannot be cancelled: its attendance is a record.
 * Audit: `program_session.cancelled`.
 */
final class CancelProgramSession
{
    public function __construct(private readonly AccessGuard $guard, private readonly AuditLogger $audit) {}

    public function __invoke(ProgramSession $session): ProgramSession
    {
        $this->guard->requirePermission('programs.manage');
        $this->guard->assertInOrganization($session);

        return DB::transaction(function () use ($session) {
            $locked = ProgramSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if (Program::query()->whereKey($locked->program_id)->value('is_sud_program')) {
                $this->guard->requirePermission('programs.view_sud');
            }
            if (! $locked->isCancelled() && $locked->starts_at->isPast()) {
                throw new DomainException('A session that has already started cannot be cancelled: its attendance is a record.', 'session_started');
            }
            if (! $locked->isCancelled()) {
                $locked->forceFill(['cancelled_at' => now()])->save();
                $this->audit->record('program_session.cancelled', $locked, summary: "Cancelled the session “{$locked->title}”");
            }
            $session->setRawAttributes($locked->getAttributes(), true);

            return $session;
        });
    }
}
