<?php

namespace App\Domain\Telehealth;

use App\Domain\Identity\PermissionResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\OrganizationMembership;
use App\Models\TelehealthSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Reads the sessions list in a fixed number of queries (one for the tab counts, one for the page), whatever the
 * number of rows: names and times come from joins, never from per-row relation loads. A viewer sees their own
 * sessions unless they hold `appointments.view_all`.
 */
final class SessionListReader
{
    public const TABS = ['upcoming', 'past', 'all'];

    public const PER_PAGE = 10;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PermissionResolver $permissions,
        private readonly TelehealthSettings $settings,
    ) {}

    public function page(OrganizationMembership $viewer, string $tab, int $page = 1): SessionListPage
    {
        $tab = in_array($tab, self::TABS, true) ? $tab : 'upcoming';
        $page = max(1, min($page, 1000));
        $now = CarbonImmutable::now('UTC');
        $counts = $this->counts($viewer, $now);

        $query = $this->base($viewer);
        $this->applyTab($query, $tab, $now);
        $rows = $query
            ->select([
                'telehealth_sessions.id', 'telehealth_sessions.status', 'a.starts_at', 'a.ends_at', 'a.timezone',
                'c.first_name', 'c.last_name', 'c.preferred_name', 'c.client_number', 's.name as service_name',
                'm.name_prefix', 'u.name as clinician_name',
            ])
            ->orderBy('a.starts_at', $tab === 'upcoming' ? 'asc' : 'desc')
            ->orderBy('telehealth_sessions.id')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
            ->get();

        $organization = $this->tenant->organizationOrFail();
        $early = min($this->settings->joinEarlyMinutes($organization), TelehealthSettings::MAX_EARLY_MINUTES);
        $canJoin = $this->permissions->membershipHas($viewer, 'telehealth.join');

        $mapped = [];
        foreach ($rows as $row) {
            $status = SessionStatus::from($row->status);
            $starts = CarbonImmutable::parse($row->starts_at)->utc();
            $ends = CarbonImmutable::parse($row->ends_at)->utc();
            $mapped[] = new SessionRow(
                id: $row->id,
                status: $status,
                startsAt: $starts,
                endsAt: $ends,
                timezone: $row->timezone,
                clientName: trim(($row->preferred_name ?: $row->first_name).' '.$row->last_name),
                clientNumber: 'CL-'.str_pad((string) $row->client_number, 4, '0', STR_PAD_LEFT),
                serviceName: $row->service_name,
                clinicianName: trim(($row->name_prefix ? $row->name_prefix.' ' : '').($row->clinician_name ?? '—')),
                joinable: $canJoin && $status->isOpen() && JoinWindow::isOpen($starts, $ends, $early, $now),
            );
        }

        return new SessionListPage($tab, $mapped, $counts, $page, self::PER_PAGE);
    }

    /**
     * The viewer's next session they may still join (open, not yet over): the one the "Start a Session" card and
     * the rail's "Join Session" button lead to. Null when there is none.
     *
     * @return array{id: string, joinable: bool}|null
     */
    public function next(OrganizationMembership $viewer): ?array
    {
        $now = CarbonImmutable::now('UTC');
        $query = $this->base($viewer);
        $this->applyTab($query, 'upcoming', $now);
        $row = $query->select(['telehealth_sessions.id', 'a.starts_at', 'a.ends_at'])->orderBy('a.starts_at')->orderBy('telehealth_sessions.id')->first();
        if ($row === null) {
            return null;
        }

        $early = min($this->settings->joinEarlyMinutes($this->tenant->organizationOrFail()), TelehealthSettings::MAX_EARLY_MINUTES);

        return [
            'id' => $row->id,
            'joinable' => JoinWindow::isOpen(CarbonImmutable::parse($row->starts_at), CarbonImmutable::parse($row->ends_at), $early, $now),
        ];
    }

    /** @return array{upcoming: int, past: int, all: int} */
    private function counts(OrganizationMembership $viewer, CarbonImmutable $now): array
    {
        $open = "'".implode("','", SessionStatus::OPEN)."'";
        $row = $this->base($viewer)
            ->selectRaw("count(*) filter (where telehealth_sessions.status in ({$open}) and a.ends_at >= ?) as upcoming", [$now->format('Y-m-d H:i:s.uP')])
            ->selectRaw('count(*) as total')
            ->first();

        $upcoming = (int) ($row->upcoming ?? 0);
        $total = (int) ($row->total ?? 0);

        return ['upcoming' => $upcoming, 'past' => $total - $upcoming, 'all' => $total];
    }

    private function applyTab(Builder $query, string $tab, CarbonImmutable $now): void
    {
        $open = SessionStatus::OPEN;
        $at = $now->format('Y-m-d H:i:s.uP');

        match ($tab) {
            'upcoming' => $query->whereIn('telehealth_sessions.status', $open)->whereRaw('a.ends_at >= ?', [$at]),
            'past' => $query->where(fn (Builder $q) => $q->whereNotIn('telehealth_sessions.status', $open)->orWhereRaw('a.ends_at < ?', [$at])),
            default => null,
        };
    }

    private function base(OrganizationMembership $viewer): Builder
    {
        $query = TelehealthSession::query()->toBase()
            ->join('appointments as a', fn (JoinClause $j) => $j->on('a.id', '=', 'telehealth_sessions.appointment_id')->on('a.organization_id', '=', 'telehealth_sessions.organization_id'))
            ->join('clients as c', fn (JoinClause $j) => $j->on('c.id', '=', 'telehealth_sessions.client_id')->on('c.organization_id', '=', 'telehealth_sessions.organization_id'))
            ->join('services as s', fn (JoinClause $j) => $j->on('s.id', '=', 'a.service_id')->on('s.organization_id', '=', 'a.organization_id'))
            ->join('organization_memberships as m', fn (JoinClause $j) => $j->on('m.id', '=', 'telehealth_sessions.clinician_membership_id')->on('m.organization_id', '=', 'telehealth_sessions.organization_id'))
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id');

        if (! $this->permissions->membershipHas($viewer, 'appointments.view_all')) {
            $query->where('telehealth_sessions.clinician_membership_id', $viewer->id);
        }

        return $query;
    }
}
