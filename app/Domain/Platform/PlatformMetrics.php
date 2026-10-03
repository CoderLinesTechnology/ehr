<?php

namespace App\Domain\Platform;

use App\Domain\Clients\ClientStatus;
use App\Domain\Saas\SubscriptionStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Client;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The numbers on the Super Admin dashboard. COUNTS only: no client, appointment
 * or any other tenant record is ever read. The two cross-tenant counts run
 * inside an explicit tenant bypass (grep-able, reviewed) and select count(*)
 * and nothing else; every other query reads platform-owned tables.
 *
 * summary() issues exactly eight queries, whatever the data volume: each
 * metric is one aggregate statement served by an index where one exists (see
 * the per-method notes for the ones that scan).
 */
final class PlatformMetrics
{
    /** Selectable reporting periods, in days. */
    public const PERIODS = [7, 30, 90];

    public const DEFAULT_PERIOD = 30;

    /** "Active" users signed in within this many days. */
    public const ACTIVE_USER_DAYS = 30;

    /** Trials ending within this many days are flagged. */
    public const TRIAL_WARNING_DAYS = 7;

    /** How many ending trials are named (the count covers all of them). */
    public const TRIALS_LISTED = 5;

    public function __construct(private readonly TenantContext $tenant) {}

    public static function period(int $days): int
    {
        return in_array($days, self::PERIODS, true) ? $days : self::DEFAULT_PERIOD;
    }

    /**
     * @return array{
     *     period_days: int,
     *     organizations: array{by_status: array<string, int>, total: int, new_in_period: int},
     *     users: array{total: int, new_in_period: int, active_30d: int},
     *     trials_ending: array{count: int, next: list<array{name: string, slug: string, ends_at: CarbonImmutable}>},
     *     live_active_clients: int,
     *     appointments_in_period: int,
     *     subscriptions_by_plan: list<array{key: string, name: string, count: int}>,
     *     failed_jobs: int,
     *     recent_audit: Collection<int, AuditLog>
     * }
     */
    public function summary(int $periodDays = self::DEFAULT_PERIOD): array
    {
        $periodDays = self::period($periodDays);
        $now = now();
        $since = $now->subDays($periodDays);

        return [
            'period_days' => $periodDays,
            'organizations' => $this->organizations($since),
            'users' => $this->users($since, $now->subDays(self::ACTIVE_USER_DAYS)),
            'trials_ending' => $this->trialsEnding($now),
            'live_active_clients' => $this->liveActiveClients(),
            'appointments_in_period' => $this->appointments($since, $now),
            'subscriptions_by_plan' => $this->liveSubscriptionsByPlan(),
            'failed_jobs' => $this->failedJobs(),
            'recent_audit' => $this->recentAudit(),
        ];
    }

    /**
     * Organizations by status (every status is present, zero or not) and how
     * many were created since $since: one pass, served by (status, created_at).
     *
     * @return array{by_status: array<string, int>, total: int, new_in_period: int}
     */
    public function organizations(CarbonInterface $since): array
    {
        $byStatus = array_fill_keys(OrganizationStatus::values(), 0);
        $new = 0;

        $rows = DB::table('organizations')
            ->selectRaw('status, count(*) AS total, count(*) FILTER (WHERE created_at >= ?) AS recent', [$since->copy()->utc()])
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $byStatus[$row->status] = (int) $row->total;
            $new += (int) $row->recent;
        }

        return ['by_status' => $byStatus, 'total' => array_sum($byStatus), 'new_in_period' => $new];
    }

    /**
     * Accounts: total, created since $since, signed in since $activeSince.
     * One sequential pass over users (a global identity table; there is no
     * index on created_at or last_login_at, see the report's index advice).
     *
     * @return array{total: int, new_in_period: int, active_30d: int}
     */
    public function users(CarbonInterface $since, CarbonInterface $activeSince): array
    {
        $row = DB::table('users')
            ->selectRaw(
                'count(*) AS total, count(*) FILTER (WHERE created_at >= ?) AS recent, count(*) FILTER (WHERE last_login_at >= ?) AS active',
                [$since->copy()->utc(), $activeSince->copy()->utc()],
            )
            ->first();

        return ['total' => (int) $row->total, 'new_in_period' => (int) $row->recent, 'active_30d' => (int) $row->active];
    }

    /**
     * Trials that end within TRIAL_WARNING_DAYS: the total, and the first few
     * by date. The window count rides on the same statement (no second query);
     * served by subscriptions (status, trial_ends_at).
     *
     * @return array{count: int, next: list<array{name: string, slug: string, ends_at: CarbonImmutable}>}
     */
    public function trialsEnding(?CarbonInterface $now = null): array
    {
        $now ??= now();

        $rows = DB::table('subscriptions AS s')
            ->join('organizations AS o', 'o.id', '=', 's.organization_id')
            ->where('s.status', SubscriptionStatus::Trialing->value)
            ->where('s.trial_ends_at', '>=', $now->copy()->utc())
            ->where('s.trial_ends_at', '<', $now->copy()->utc()->addDays(self::TRIAL_WARNING_DAYS))
            ->orderBy('s.trial_ends_at')
            ->limit(self::TRIALS_LISTED)
            ->selectRaw('o.name, o.slug, s.trial_ends_at, count(*) OVER () AS total')
            ->get();

        return [
            'count' => $rows->isEmpty() ? 0 : (int) $rows->first()->total,
            'next' => $rows->map(fn ($row) => [
                'name' => $row->name,
                'slug' => $row->slug,
                'ends_at' => CarbonImmutable::parse($row->trial_ends_at),
            ])->all(),
        ];
    }

    /**
     * Active LIVE clients across every organization: a count, inside an
     * explicit bypass of the tenant scope. Demo clients never count. This is a
     * sequential count (no index leads on record_environment); see the report.
     */
    public function liveActiveClients(): int
    {
        return $this->tenant->bypass(
            fn () => Client::query()->live()->where('status', ClientStatus::Active->value)->count(),
        );
    }

    /**
     * Live appointments that started in [$since, $until): a count, inside an
     * explicit bypass of the tenant scope.
     */
    public function appointments(CarbonInterface $since, CarbonInterface $until): int
    {
        return $this->tenant->bypass(
            fn () => Appointment::query()->live()
                ->where('starts_at', '>=', $since->copy()->utc())
                ->where('starts_at', '<', $until->copy()->utc())
                ->count(),
        );
    }

    /** @return list<array{key: string, name: string, count: int}> live subscriptions per plan, in plan order */
    public function liveSubscriptionsByPlan(): array
    {
        return DB::table('subscriptions AS s')
            ->join('plans AS p', 'p.id', '=', 's.plan_id')
            ->whereIn('s.status', SubscriptionStatus::LIVE)
            ->groupBy('p.id', 'p.key', 'p.name', 'p.sort')
            ->orderBy('p.sort')
            ->orderBy('p.name')
            ->selectRaw('p.key, p.name, count(*) AS total')
            ->get()
            ->map(fn ($row) => ['key' => $row->key, 'name' => $row->name, 'count' => (int) $row->total])
            ->all();
    }

    public function failedJobs(): int
    {
        return DB::table('failed_jobs')->count();
    }

    /**
     * The latest platform and system audit entries. Only what
     * AuditLog::platformVisible() allows: activity inside organizations is
     * never part of this list.
     *
     * @return Collection<int, AuditLog>
     */
    public function recentAudit(int $limit = 10): Collection
    {
        return AuditLog::query()
            ->platformVisible()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 50)))
            ->get();
    }
}
