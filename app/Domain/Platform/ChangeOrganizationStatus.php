<?php

namespace App\Domain\Platform;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Platform\Events\OrganizationStatusChanged;
use App\Domain\Shared\DomainException;
use App\Models\Organization;
use App\Models\OrganizationStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one way an organization's lifecycle status changes: locks the row,
 * checks the transition, writes insert-only history, audits, and fires
 * OrganizationStatusChanged after commit.
 */
final class ChangeOrganizationStatus
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(Organization $organization, OrganizationStatus $to, ?User $actor, ?string $reason = null): Organization
    {
        $reason = $reason !== null ? trim($reason) : null;

        if ($to->isRestrictive() && ($reason === null || $reason === '')) {
            throw new DomainException('A reason is required to '.mb_strtolower($to->actionLabel()).' an organization.', 'reason_required', 'reason');
        }

        return DB::transaction(function () use ($organization, $to, $actor, $reason) {
            /** @var Organization $locked */
            $locked = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $from = $locked->status;

            if ($from === $to) {
                return $organization;
            }

            if (! $from->canTransitionTo($to)) {
                throw new DomainException("An organization that is {$from->label()} cannot be changed to {$to->label()}.", 'invalid_transition');
            }

            $locked->forceFill([
                'status' => $to,
                'status_reason' => $reason,
                'status_changed_at' => now(),
            ])->save();

            $history = new OrganizationStatusHistory;
            $history->forceFill([
                'organization_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason,
                'actor_user_id' => $actor?->id,
                'occurred_at' => now(),
            ])->save();

            $this->audit->record(
                'organization.status_changed',
                subject: $locked,
                before: ['status' => $from->value],
                after: ['status' => $to->value],
                metadata: array_filter(['reason' => $reason]),
                summary: "{$locked->name}: {$from->label()} → {$to->label()}",
                context: AuditContext::Platform,
            );

            OrganizationStatusChanged::dispatch($locked, $from, $to, $reason, $actor?->id);

            $organization->setRawAttributes($locked->getAttributes(), true);

            return $organization;
        });
    }
}
