<?php

namespace App\Domain\Scheduling;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Events\AppointmentStatusChanged;
use App\Domain\Scheduling\Support\AppointmentHistory;
use App\Domain\Scheduling\Support\SchedulingSettings;
use App\Domain\Scheduling\Support\TenantGuard;
use App\Domain\Scheduling\Support\Text;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The one way an appointment's status changes: locks the row, checks the
 * state machine and the clock, stamps the milestone column, records who and
 * why in the insert-only history, audits, fires AppointmentStatusChanged after
 * commit, and hands the caller's instance back in sync.
 *
 * A client cancellation inside the notice window (the service's override, else
 * the organization's setting) is recorded as late.
 */
final class TransitionAppointment
{
    public const MAX_REASON = 500;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly SchedulingSettings $settings,
    ) {}

    public function __invoke(
        Appointment $appointment,
        AppointmentStatus $to,
        ?User $actor = null,
        ?string $reason = null,
        CancellationKind|string|null $cancellationKind = null,
    ): Appointment {
        $organization = $this->tenant->organizationOrFail();
        TenantGuard::assertOwned($organization->id, $appointment);

        if ($to === AppointmentStatus::Rescheduled) {
            throw new DomainException('Use “Reschedule” to move an appointment to another time.', 'use_reschedule', 'status');
        }

        $reason = Text::optional($reason, self::MAX_REASON, 'reason', 'reason');
        $kind = $this->cancellationKind($to, $cancellationKind);

        return DB::transaction(function () use ($organization, $appointment, $to, $actor, $reason, $kind) {
            /** @var Appointment $locked */
            $locked = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            $from = $locked->status;

            if ($from === $to) {
                $appointment->setRawAttributes($locked->getAttributes(), true);

                return $appointment;
            }

            if (! $from->canTransitionTo($to)) {
                throw new DomainException("An appointment that is “{$from->label()}” cannot be changed to “{$to->label()}”.", 'invalid_transition', 'status');
            }

            $now = now();
            if (! $to->canEnterAt($locked->starts_at, $now)) {
                throw new DomainException($this->tooEarlyMessage($to), 'too_early', 'status');
            }

            $changes = ['status' => $to];
            if ($column = $to->timestampColumn()) {
                $changes[$column] = $now;
            }
            if ($to === AppointmentStatus::Cancelled) {
                $changes['cancellation_kind'] = $kind->value;
                $changes['cancellation_reason'] = $reason;
                $changes['late_cancellation'] = $kind === CancellationKind::Client && $this->insideNoticeWindow($locked, $organization, $now);
            }

            $locked->forceFill($changes)->save();
            $this->record($locked, $from, $to, $reason, $actor);

            AppointmentStatusChanged::dispatch($locked, $from, $to, $reason, $actor?->id);

            $appointment->setRawAttributes($locked->getAttributes(), true);

            return $appointment;
        });
    }

    /**
     * RescheduleAppointment only: the original, already locked inside the
     * caller's transaction, steps aside for its replacement. History and audit
     * as for any transition; the event is AppointmentRescheduled, fired by the
     * caller once the replacement exists.
     */
    public function markRescheduled(Appointment $locked, ?User $actor, ?string $reason): void
    {
        $from = $locked->status;

        if (! $from->canReschedule()) {
            throw new DomainException('Only scheduled or confirmed appointments can be rescheduled.', 'cannot_reschedule', 'status');
        }

        $locked->forceFill(['status' => AppointmentStatus::Rescheduled])->save();
        $this->record($locked, $from, AppointmentStatus::Rescheduled, $reason, $actor);
    }

    private function record(Appointment $locked, AppointmentStatus $from, AppointmentStatus $to, ?string $reason, ?User $actor): void
    {
        AppointmentHistory::record($locked, $from, $to, $reason, $actor);

        $after = ['status' => $to->value];
        if ($to === AppointmentStatus::Cancelled) {
            $after += [
                'cancellation_kind' => $locked->cancellation_kind,
                'late_cancellation' => $locked->late_cancellation,
            ];
        }

        $this->audit->record(
            'appointment.status_changed',
            subject: $locked,
            before: ['status' => $from->value],
            after: $after,
            metadata: array_filter(['reason' => $reason]),
            summary: "Appointment {$from->label()} → {$to->label()}",
        );
    }

    private function cancellationKind(AppointmentStatus $to, CancellationKind|string|null $kind): ?CancellationKind
    {
        if ($to !== AppointmentStatus::Cancelled) {
            return null;
        }

        $kind = is_string($kind) ? CancellationKind::tryFrom($kind) : $kind;

        return $kind ?? throw new DomainException('Say whether the client or the practice cancelled.', 'cancellation_kind_required', 'cancellation_kind');
    }

    private function insideNoticeWindow(Appointment $locked, Organization $organization, CarbonImmutable $now): bool
    {
        $hours = Service::query()->whereKey($locked->service_id)->value('cancellation_notice_hours')
            ?? $this->settings->cancellationNoticeHours($organization);

        return $now->greaterThan($locked->starts_at->subHours((int) $hours));
    }

    private function tooEarlyMessage(AppointmentStatus $to): string
    {
        $minutes = (int) $to->earliestMinutesBeforeStart();

        return $minutes === 0
            ? "An appointment can only be marked “{$to->label()}” once it has started."
            : 'An appointment can be marked “'.$to->label().'” at most '.intdiv($minutes, 60).' hours before it starts.';
    }
}
