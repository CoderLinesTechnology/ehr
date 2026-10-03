<?php

namespace App\Domain\Dashboard;

use App\Domain\Clients\ClientStatus;
use App\Domain\Clients\ClientVisibility;
use App\Domain\Identity\PermissionResolver;
use App\Domain\Organization\OnboardingChecklist;
use App\Domain\Saas\EntitlementService;
use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Shared\RecordEnvironment;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;

/**
 * Read model of the staff dashboard: what one member sees on the home screen.
 *
 * A fixed number of queries whatever the data (one aggregate for clients, one for appointments,
 * one list each for upcoming appointments, today's schedule and recent clients, plus the
 * permission lookup and, for organization admins still onboarding, the checklist's own reads).
 * Everything is tenant-scoped by the models and permission-aware:
 *
 *   appointments.view_all → every appointment      appointments.view → the member's own
 *   clients.view_all      → every client           clients.view      → ClientVisibility's rule
 *
 * Counts and trends use LIVE records only (demo data is for exploring, never for numbers). The two
 * lists include demo rows so a demo organization is not an empty screen; each carries `demo`.
 * Trends compare the last 30 days with the 30 days before and are null (hidden) without a baseline.
 */
final class StaffDashboard
{
    public const UPCOMING_LIMIT = 5;

    public const SCHEDULE_LIMIT = 4;

    public const RECENT_CLIENTS_LIMIT = 4;

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * @return array{
     *   greeting: string, firstName: string, stats: list<array<string, mixed>>,
     *   canSeeAppointments: bool, upcoming: list<array<string, mixed>>, today: list<array<string, mixed>>,
     *   recentClients: list<array<string, mixed>>, quickActions: list<array<string, mixed>>,
     *   onboarding: ?list<array<string, mixed>>
     * }
     */
    public function for(User $user, OrganizationMembership $membership, Organization $organization, ?CarbonImmutable $now = null): array
    {
        $timezone = $organization->timezone ?: config('app.timezone');
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $local = $now->setTimezone($timezone);

        $has = fn (string $permission): bool => $membership->isActive() && $this->permissions->membershipHas($membership, $permission);
        $seesClients = ClientVisibility::hasAnyAccess($membership);
        $seesAppointments = $has('appointments.view') || $has('appointments.view_all');
        if ($seesAppointments && ! $this->entitlements->allows($organization, 'calendar')) {
            $seesAppointments = false;
        }

        $clientStats = $seesClients ? $this->clientStats($membership, $now) : null;
        $appointmentStats = $seesAppointments ? $this->appointmentStats($membership, $now, $local, $has('appointments.view_all')) : null;

        return [
            'greeting' => self::greeting((int) $local->format('G')),
            'firstName' => self::firstName($user->name),
            'stats' => $this->stats($clientStats, $appointmentStats),
            'canSeeAppointments' => $seesAppointments,
            'upcoming' => $seesAppointments ? $this->upcoming($membership, $now, $has('appointments.view_all')) : [],
            'today' => $seesAppointments ? $this->today($membership, $local, $has('appointments.view_all')) : [],
            'recentClients' => $seesClients ? $this->recentClients($membership) : [],
            'quickActions' => $this->quickActions($organization, $has),
            'onboarding' => $has('organization.settings.manage') ? $this->onboarding($organization) : null,
        ];
    }

    public static function greeting(int $hour): string
    {
        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    public static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $parts[0] ?? 'there';
    }

    /** Percent change, one decimal; null without a baseline. */
    public static function trend(int $now, int $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100) : null;
    }

    /**
     * The card set. Order is the comp's; Messages and Programs take their slots when those modules exist.
     *
     * @return list<array<string, mixed>>
     */
    private function stats(?array $clients, ?array $appointments): array
    {
        $cards = [];

        if ($clients !== null) {
            $cards[] = ['key' => 'clients', 'label' => 'Total Clients', 'icon' => 'users', 'value' => $clients['total'], 'trend' => self::trend($clients['total'], $clients['total_before']), 'href' => self::route('app.clients.index')];
        }
        if ($appointments !== null) {
            $cards[] = ['key' => 'upcoming', 'label' => 'Upcoming Appointments', 'icon' => 'calendar-days', 'value' => $appointments['upcoming'], 'trend' => self::trend($appointments['upcoming'], $appointments['previous']), 'href' => self::route('app.calendar.index')];
        }
        // Slot 3 (comp: Messages) and slot 4 (comp: Active Programs): until those modules exist, today's work and new clients.
        if ($appointments !== null) {
            $cards[] = ['key' => 'today', 'label' => "Today's Appointments", 'icon' => 'calendar-check', 'value' => $appointments['today'], 'trend' => null, 'hint' => $appointments['today'] === 0 ? 'Nothing booked today' : $appointments['today_remaining'].' still to come', 'href' => self::route('app.calendar.index')];
        }
        if ($clients !== null) {
            $cards[] = ['key' => 'new_clients', 'label' => 'New Clients (30 days)', 'icon' => 'user-plus', 'value' => $clients['new'], 'trend' => self::trend($clients['new'], $clients['new_before']), 'href' => self::route('app.clients.index')];
        }

        return $cards;
    }

    /** @return array{total: int, total_before: int, new: int, new_before: int} */
    private function clientStats(OrganizationMembership $membership, CarbonImmutable $now): array
    {
        $d30 = $now->subDays(30)->toDateTimeString();
        $d60 = $now->subDays(60)->toDateTimeString();
        $archived = ClientStatus::Archived->value;

        $row = ClientVisibility::apply(Client::query()->live(), $membership)
            ->where('status', '!=', $archived)
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where clients.created_at <= ?) as total_before', [$d30])
            ->selectRaw('count(*) filter (where clients.created_at > ?) as new', [$d30])
            ->selectRaw('count(*) filter (where clients.created_at > ? and clients.created_at <= ?) as new_before', [$d60, $d30])
            ->toBase()->first();

        return ['total' => (int) $row->total, 'total_before' => (int) $row->total_before, 'new' => (int) $row->new, 'new_before' => (int) $row->new_before];
    }

    /** @return array{upcoming: int, previous: int, today: int, today_remaining: int} */
    private function appointmentStats(OrganizationMembership $membership, CarbonImmutable $now, CarbonImmutable $local, bool $all): array
    {
        $fmt = fn (CarbonImmutable $t): string => $t->utc()->toDateTimeString();
        $dayStart = $local->startOfDay();
        $dayEnd = $dayStart->addDay();
        $upcomingStatuses = "'".implode("','", AppointmentStatus::UPCOMING)."'";
        $heldStatuses = "'".implode("','", AppointmentStatus::OCCUPYING)."'";

        $row = $this->visible(Appointment::query()->live(), $membership, $all)
            ->where('starts_at', '>=', $fmt($now->subDays(30)))
            ->where('starts_at', '<', $fmt($now->addDays(30)->max($dayEnd)))
            ->selectRaw("count(*) filter (where status in ({$upcomingStatuses}) and starts_at >= ? and starts_at < ?) as upcoming", [$fmt($now), $fmt($now->addDays(30))])
            ->selectRaw("count(*) filter (where status in ({$heldStatuses}) and starts_at >= ? and starts_at < ?) as previous", [$fmt($now->subDays(30)), $fmt($now)])
            ->selectRaw("count(*) filter (where status in ({$heldStatuses}) and starts_at >= ? and starts_at < ?) as today", [$fmt($dayStart), $fmt($dayEnd)])
            ->selectRaw("count(*) filter (where status in ({$upcomingStatuses}, 'checked_in', 'in_progress') and starts_at >= ? and starts_at < ?) as today_remaining", [$fmt($now), $fmt($dayEnd)])
            ->toBase()->first();

        return ['upcoming' => (int) $row->upcoming, 'previous' => (int) $row->previous, 'today' => (int) $row->today, 'today_remaining' => (int) $row->today_remaining];
    }

    /** @return list<array<string, mixed>> */
    private function upcoming(OrganizationMembership $membership, CarbonImmutable $now, bool $all): array
    {
        $rows = $this->appointmentRows($membership, $all)
            ->whereIn('appointments.status', AppointmentStatus::UPCOMING)
            ->where('appointments.starts_at', '>=', $now->toDateTimeString())
            ->orderBy('appointments.starts_at')->orderBy('appointments.id')
            ->limit(self::UPCOMING_LIMIT)->get();

        return $rows->map(fn ($row) => $this->appointment($row, $now))->all();
    }

    /** @return list<array<string, mixed>> */
    private function today(OrganizationMembership $membership, CarbonImmutable $local, bool $all): array
    {
        $rows = $this->appointmentRows($membership, $all)
            ->whereIn('appointments.status', AppointmentStatus::OCCUPYING)
            ->where('appointments.starts_at', '>=', $local->startOfDay()->utc()->toDateTimeString())
            ->where('appointments.starts_at', '<', $local->startOfDay()->addDay()->utc()->toDateTimeString())
            ->orderBy('appointments.starts_at')->orderBy('appointments.id')
            ->limit(self::SCHEDULE_LIMIT)->get();

        return $rows->map(fn ($row) => $this->appointment($row, $local))->all();
    }

    /** @return \Illuminate\Database\Query\Builder */
    private function appointmentRows(OrganizationMembership $membership, bool $all)
    {
        $query = Appointment::query()
            ->join('clients', fn ($join) => $join->on('clients.id', '=', 'appointments.client_id')->on('clients.organization_id', '=', 'appointments.organization_id'))
            ->join('services', fn ($join) => $join->on('services.id', '=', 'appointments.service_id')->on('services.organization_id', '=', 'appointments.organization_id'))
            ->select([
                'appointments.id', 'appointments.client_id', 'appointments.starts_at', 'appointments.timezone', 'appointments.status',
                'appointments.record_environment', 'clients.first_name', 'clients.last_name', 'services.name as service_name',
            ]);

        return $this->visible($query, $membership, $all)->toBase();
    }

    /** @param Builder<Appointment> $query @return Builder<Appointment> */
    private function visible(Builder $query, OrganizationMembership $membership, bool $all): Builder
    {
        return $all ? $query : $query->where($query->qualifyColumn('clinician_membership_id'), $membership->id);
    }

    /** @return array<string, mixed> */
    private function appointment(object $row, CarbonImmutable $reference): array
    {
        $start = CarbonImmutable::parse($row->starts_at, 'UTC')->setTimezone($row->timezone);
        $status = AppointmentStatus::from($row->status);
        $today = $reference->setTimezone($row->timezone)->startOfDay();
        $time = ltrim($start->format('g:i A'), '0');
        $day = $start->startOfDay();

        return [
            'id' => $row->id,
            'clientId' => $row->client_id,
            'name' => trim($row->first_name.' '.$row->last_name),
            'service' => $row->service_name,
            'time' => $time,
            'when' => match (true) {
                $day->equalTo($today) => 'Today, '.$time,
                $day->equalTo($today->addDay()) => 'Tomorrow, '.$time,
                default => $start->format('D j M').', '.$time,
            },
            'status' => $status,
            'statusLabel' => $status->badgeLabel(),
            'statusTone' => $status->badgeTone(),
            'demo' => $row->record_environment === RecordEnvironment::Demo->value,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentClients(OrganizationMembership $membership): array
    {
        return ClientVisibility::apply(Client::query(), $membership)
            ->select(['id', 'organization_id', 'record_environment', 'status', 'first_name', 'last_name', 'created_at'])
            ->where('status', '!=', ClientStatus::Archived->value)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::RECENT_CLIENTS_LIMIT)->get()
            ->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => $client->fullName(),
                'status' => $client->status,
                'demo' => $client->isDemo(),
            ])->all();
    }

    /**
     * Only actions whose route exists, whose module the plan includes and the member may perform.
     *
     * @param  callable(string): bool  $has
     * @return list<array<string, mixed>>
     */
    private function quickActions(Organization $organization, callable $has): array
    {
        $candidates = [
            ['label' => 'Add New Client', 'icon' => 'user-plus', 'route' => 'app.clients.create', 'permission' => 'clients.create', 'feature' => 'clients'],
            ['label' => 'Schedule Appointment', 'icon' => 'calendar', 'route' => 'app.appointments.create', 'permission' => 'appointments.create', 'feature' => 'calendar'],
            ['label' => 'Send Message', 'icon' => 'message-circle', 'route' => 'app.messages.index', 'permission' => null, 'feature' => 'messaging'],
            ['label' => 'View Resources', 'icon' => 'book-open', 'route' => 'app.resources.index', 'permission' => null, 'feature' => null],
        ];

        $actions = [];
        foreach ($candidates as $c) {
            if (! Route::has($c['route']) || ($c['permission'] !== null && ! $has($c['permission']))
                || ($c['feature'] !== null && ! $this->entitlements->allows($organization, $c['feature']))) {
                continue;
            }
            $actions[] = ['label' => $c['label'], 'icon' => $c['icon'], 'url' => route($c['route'], ['organization' => $organization->slug])];
        }

        return $actions;
    }

    /** The checklist while onboarding is incomplete, else null. @return ?list<array<string, mixed>> */
    private function onboarding(Organization $organization): ?array
    {
        if ($organization->onboarding_completed_at !== null) {
            return null;
        }
        $steps = OnboardingChecklist::for($organization);

        return OnboardingChecklist::isComplete($steps) ? null : $steps;
    }

    private static function route(string $name): ?string
    {
        return Route::has($name) ? route($name, ['organization' => tenant()->organizationOrFail()->slug]) : null;
    }
}
