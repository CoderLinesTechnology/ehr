<?php

namespace App\Domain\Clients;

use App\Domain\Identity\PermissionResolver;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the client list shows beyond the client row itself, each in ONE bounded
 * query whatever the page size:
 *
 *  - next appointment / last visit for the clients on the page;
 *  - the four stat cards (live clients only: demo data is never counted).
 *
 * Appointment data follows the profile's Appointments tab: someone with
 * `appointments.view_all` sees every appointment, someone with only
 * `appointments.view` sees the ones they are the clinician on, and everyone
 * else (or an organization without the calendar module) sees none.
 */
final class ClientListRows
{
    /** Appointments that are still going to happen. */
    private const UPCOMING = ['scheduled', 'confirmed'];

    /** Appointments that were (or will be) real activity, i.e. not cancelled, rescheduled away or missed. */
    private const ACTIVITY = ['scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed'];

    public const WINDOW_DAYS = 30;

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * Which appointments the member may see: 'all', 'own', or null (none, or no calendar module).
     *
     * @return 'all'|'own'|null
     */
    public function appointmentAccess(OrganizationMembership $membership): ?string
    {
        if (! $membership->isActive() || ! $this->entitlements->allows(tenant()->organizationOrFail(), FeatureRegistry::CALENDAR)) {
            return null;
        }

        return match (true) {
            $this->permissions->membershipHas($membership, 'appointments.view_all') => 'all',
            $this->permissions->membershipHas($membership, 'appointments.view') => 'own',
            default => null,
        };
    }

    /**
     * The next scheduled appointment and the last completed visit of each client.
     * One statement: DISTINCT ON picks one row per (client, kind) from the clients' own rows
     * (index: organization_id, client_id, starts_at).
     *
     * @param  Collection<int, Client>  $clients  the page's rows (already visibility-filtered)
     * @return array<string, array{next: array{at: CarbonImmutable, timezone: string}|null, last: CarbonImmutable|null}>
     */
    public function appointmentsFor(Collection $clients, OrganizationMembership $membership, ?string $access): array
    {
        $rows = [];
        foreach ($clients as $client) {
            $rows[$client->id] = ['next' => null, 'last' => null];
        }

        if ($access === null || $clients->isEmpty()) {
            return $rows;
        }

        $ids = $clients->pluck('id')->all();
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $upcoming = "'".implode("','", self::UPCOMING)."'";
        $now = CarbonImmutable::now()->utc()->format('Y-m-d H:i:s.uP');

        $bindings = [$now, $now, $membership->organization_id, ...$ids];
        $own = '';
        if ($access === 'own') {
            $own = 'and clinician_membership_id = ?';
            $bindings[] = $membership->id;
        }

        $found = DB::select(
            "select distinct on (client_id, kind) client_id, kind, starts_at, timezone from (
                select client_id, starts_at, timezone,
                    case when status in ({$upcoming}) and starts_at >= ?::timestamptz then 'next'
                         when status = 'completed' and starts_at < ?::timestamptz then 'last' end as kind
                from appointments
                where organization_id = ? and client_id in ({$marks}) {$own}
            ) a where kind is not null
            order by client_id, kind, case when kind = 'next' then extract(epoch from starts_at) else -extract(epoch from starts_at) end",
            $bindings,
        );

        foreach ($found as $row) {
            $at = CarbonImmutable::parse($row->starts_at)->utc();
            if ($row->kind === 'next') {
                $rows[$row->client_id]['next'] = ['at' => $at, 'timezone' => $row->timezone];
            } else {
                $rows[$row->client_id]['last'] = $at;
            }
        }

        return $rows;
    }

    /**
     * The stat cards. Every trend compares with the previous 30 days and is null
     * when there is nothing to compare with (never a made-up number):
     *
     *  - total / active: the count now vs. the same clients' count 30 days ago, i.e. growth from
     *    new registrations among clients still in care (there is no status history to replay);
     *  - new: registered in the last 30 days vs. the 30 days before;
     *  - upcoming: appointments in the next 30 days vs. activity (not cancelled/missed) in the last 30.
     *
     * @return array{total: array{value: int, trend: ?float}, active: array{value: int, trend: ?float}, upcoming: array{value: ?int, trend: ?float}, new: array{value: int, trend: ?float}}
     */
    public function stats(OrganizationMembership $membership, ?string $access): array
    {
        $now = CarbonImmutable::now()->utc();
        $fmt = static fn (CarbonImmutable $t): string => $t->format('Y-m-d H:i:s.uP');
        $ago30 = $now->subDays(self::WINDOW_DAYS);
        $ago60 = $now->subDays(2 * self::WINDOW_DAYS);

        $clients = Client::query()->live()->where('status', '!=', ClientStatus::Archived->value);
        ClientVisibility::apply($clients, $membership);
        $c = $clients->toBase()->selectRaw(
            "count(*) as total,
             count(*) filter (where created_at < ?::timestamptz) as total_prev,
             count(*) filter (where status = 'active') as active,
             count(*) filter (where status = 'active' and created_at < ?::timestamptz) as active_prev,
             count(*) filter (where created_at >= ?::timestamptz) as new_now,
             count(*) filter (where created_at >= ?::timestamptz and created_at < ?::timestamptz) as new_prev",
            [$fmt($ago30), $fmt($ago30), $fmt($ago30), $fmt($ago60), $fmt($ago30)],
        )->first();

        $upcoming = null;
        $upcomingTrend = null;
        if ($access !== null) {
            $appointments = Appointment::query()->where('record_environment', 'live');
            if ($access === 'own') {
                $appointments->where('clinician_membership_id', $membership->id);
            } elseif (! ClientVisibility::seesAll($membership)) {
                $appointments->whereIn('client_id', ClientVisibility::apply(Client::query()->select('id'), $membership));
            }
            $a = $appointments->toBase()->selectRaw(
                'count(*) filter (where status in ('.$this->quoted(self::UPCOMING).') and starts_at >= ?::timestamptz and starts_at < ?::timestamptz) as upcoming,
                 count(*) filter (where status in ('.$this->quoted(self::ACTIVITY).') and starts_at >= ?::timestamptz and starts_at < ?::timestamptz) as previous',
                [$fmt($now), $fmt($now->addDays(self::WINDOW_DAYS)), $fmt($ago30), $fmt($now)],
            )->where('starts_at', '>=', $fmt($ago30))->where('starts_at', '<', $fmt($now->addDays(self::WINDOW_DAYS)))->first();
            $upcoming = (int) $a->upcoming;
            $upcomingTrend = $this->change($upcoming, (int) $a->previous);
        }

        return [
            'total' => ['value' => (int) $c->total, 'trend' => $this->change((int) $c->total, (int) $c->total_prev)],
            'active' => ['value' => (int) $c->active, 'trend' => $this->change((int) $c->active, (int) $c->active_prev)],
            'upcoming' => ['value' => $upcoming, 'trend' => $upcomingTrend],
            'new' => ['value' => (int) $c->new_now, 'trend' => $this->change((int) $c->new_now, (int) $c->new_prev)],
        ];
    }

    /** Percent change from $before to $now, rounded to a whole percent; null when there is no baseline. */
    private function change(int $now, int $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100) : null;
    }

    /** @param list<string> $values constants only (never user input) */
    private function quoted(array $values): string
    {
        return "'".implode("','", $values)."'";
    }
}
