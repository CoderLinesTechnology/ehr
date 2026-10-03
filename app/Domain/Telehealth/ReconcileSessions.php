<?php

namespace App\Domain\Telehealth;

use App\Domain\Scheduling\Modality;
use App\Domain\Tenancy\TenantContext;
use App\Models\Appointment;
use App\Models\TelehealthSession;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Self-healing for the one thing the scheduling events cannot see: an appointment whose modality was edited
 * (in person ↔ telehealth) without being rebooked. Run when the sessions list opens, bounded to a few dozen rows
 * and idempotent: a telehealth appointment that has no session gets one, and an open session whose appointment is
 * no longer telehealth is cancelled. Two cheap, indexed queries when there is nothing to do.
 */
final class ReconcileSessions
{
    public const LIMIT = 50;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SyncTelehealthSession $sync,
    ) {}

    public function __invoke(): int
    {
        $this->tenant->organizationOrFail();
        $fixed = 0;

        $missing = Appointment::query()
            ->where('modality', Modality::Telehealth->value)
            ->whereIn('status', ['scheduled', 'confirmed', 'checked_in', 'in_progress'])
            ->where('starts_at', '>=', now()->subDay())   // recent and future only: the (organization, starts_at) index skips the history
            ->whereNotExists(fn (QueryBuilder $sessions) => $sessions->from('telehealth_sessions')
                ->whereColumn('telehealth_sessions.appointment_id', 'appointments.id')
                ->whereColumn('telehealth_sessions.organization_id', 'appointments.organization_id'))
            ->orderBy('starts_at')->limit(self::LIMIT)->get();

        foreach ($missing as $appointment) {
            ($this->sync)($appointment);
            $fixed++;
        }

        $stale = Appointment::query()
            ->where('modality', '!=', Modality::Telehealth->value)
            ->whereIn('id', TelehealthSession::query()->select('appointment_id')
                ->whereIn('status', SessionStatus::OPEN)->where('starts_at', '>=', now()->subDay()))
            ->limit(self::LIMIT)->get();

        foreach ($stale as $appointment) {
            ($this->sync)($appointment, 'Appointment is no longer telehealth');
            $fixed++;
        }

        return $fixed;
    }
}
