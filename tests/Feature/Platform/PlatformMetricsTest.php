<?php

namespace Tests\Feature\Platform;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Platform\ChangeOrganizationStatus;
use App\Domain\Platform\OrganizationStatus;
use App\Domain\Platform\PlatformDashboard;
use App\Domain\Platform\PlatformMetrics;
use App\Domain\Saas\ChangeSubscriptionStatus;
use App\Domain\Saas\SubscriptionStatus;
use App\Domain\Tenancy\MissingTenantContext;
use App\Domain\Tenancy\TenantContext;
use App\Models\Client;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class PlatformMetricsTest extends PlatformTestCase
{
    private function metrics(): PlatformMetrics
    {
        return app(PlatformMetrics::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC'));
    }

    #[Test]
    public function organizations_are_counted_by_status_with_every_status_present_and_new_ones_by_period(): void
    {
        $admin = $this->signInAsPlatform();
        $active = $this->createOrganization()->organization;
        $this->createOrganization();
        $this->createOrganization(status: OrganizationStatus::Trial);
        $this->createOrganization(status: OrganizationStatus::Pending);
        $suspended = $this->createOrganization()->organization;
        app(ChangeOrganizationStatus::class)($suspended, OrganizationStatus::Suspended, $admin, 'Unpaid');

        // One organization is old news: created 100 days ago.
        $active->forceFill(['created_at' => now()->subDays(100)])->save();
        // One was created 20 days ago.
        $suspended->forceFill(['created_at' => now()->subDays(20)])->save();

        $summary = $this->metrics()->summary(30);

        $this->assertSame(
            ['pending' => 1, 'trial' => 1, 'active' => 2, 'suspended' => 1, 'archived' => 0, 'cancelled' => 0],
            $summary['organizations']['by_status'],
            'Every status is present, zero or not.',
        );
        $this->assertSame(5, $summary['organizations']['total']);
        $this->assertSame(4, $summary['organizations']['new_in_period'], 'Five organizations, one of them created 100 days ago.');
        $this->assertSame(30, $summary['period_days']);

        $this->assertSame(4, $this->metrics()->summary(90)['organizations']['new_in_period']);
        $this->assertSame(5, $this->metrics()->summary(90)['organizations']['total']);

        $suspended->forceFill(['created_at' => now()->subDays(20)])->save();
        $this->assertSame(3, $this->metrics()->summary(7)['organizations']['new_in_period'], 'The suspended one is 20 days old, so it falls outside 7 days.');
    }

    #[Test]
    public function the_period_is_one_of_seven_thirty_or_ninety_days(): void
    {
        $this->signInAsPlatform();

        foreach ([7, 30, 90] as $days) {
            $this->assertSame($days, $this->metrics()->summary($days)['period_days']);
        }
        foreach ([0, 1, 31, 365, -7] as $days) {
            $this->assertSame(30, $this->metrics()->summary($days)['period_days'], "{$days} falls back to the default.");
        }

        $dashboard = app(PlatformDashboard::class);
        $this->assertSame(PlatformMetrics::PERIODS, PlatformDashboard::PERIODS);
        $this->assertSame(7, $dashboard(7)['period_days']);
    }

    #[Test]
    public function users_are_counted_in_total_new_and_active_in_the_last_thirty_days(): void
    {
        $admin = $this->signInAsPlatform();
        $admin->forceFill(['last_login_at' => now()->subDays(1)])->save();

        $old = User::factory()->create(['created_at' => now()->subDays(200), 'last_login_at' => now()->subDays(100)]);
        User::factory()->create(['created_at' => now()->subDays(20), 'last_login_at' => now()->subDays(25)]);
        User::factory()->create(['created_at' => now()->subDays(3), 'last_login_at' => null]);

        $users = $this->metrics()->summary(30)['users'];

        $this->assertSame(4, $users['total']);
        $this->assertSame(3, $users['new_in_period'], 'The admin, one 20 days old and one 3 days old.');
        $this->assertSame(2, $users['active_30d'], 'Signed in within 30 days: the admin and the 25-days-ago user.');
        $this->assertSame(2, $this->metrics()->summary(7)['users']['new_in_period']);
        $this->assertNotNull($old);
    }

    #[Test]
    public function trials_ending_within_seven_days_are_counted_and_the_first_few_named(): void
    {
        $this->signInAsPlatform();
        $names = [];
        foreach ([1, 2, 3, 4, 5, 6, 6] as $i => $days) {
            $organization = $this->createOrganization(['name' => "Trial Number {$i}"], status: OrganizationStatus::Trial)->organization;
            Subscription::query()->where('organization_id', $organization->id)->update(['trial_ends_at' => now()->addDays($days)->addHours($i)]);
            $names[] = $organization->name;
        }
        // Outside the window: ends in 9 days, ended yesterday, and one that is not a trial any more.
        $later = $this->createOrganization(['name' => 'Later Trial'], status: OrganizationStatus::Trial)->organization;
        Subscription::query()->where('organization_id', $later->id)->update(['trial_ends_at' => now()->addDays(9)]);
        $past = $this->createOrganization(['name' => 'Past Trial'], status: OrganizationStatus::Trial)->organization;
        Subscription::query()->where('organization_id', $past->id)->update(['trial_ends_at' => now()->subDay()]);
        $this->createOrganization(['name' => 'Paying']);

        $trials = $this->metrics()->summary(30)['trials_ending'];

        $this->assertSame(7, $trials['count'], 'All seven in the window are counted.');
        $this->assertCount(PlatformMetrics::TRIALS_LISTED, $trials['next'], 'Only the first few are named.');
        $this->assertSame(array_slice($names, 0, 5), array_column($trials['next'], 'name'), 'Soonest first.');
        $this->assertInstanceOf(CarbonImmutable::class, $trials['next'][0]['ends_at']);
        $this->assertNotEmpty($trials['next'][0]['slug']);
    }

    #[Test]
    public function with_no_trial_ending_the_count_is_zero(): void
    {
        $this->signInAsPlatform();
        $this->createOrganization();

        $this->assertSame(['count' => 0, 'next' => []], $this->metrics()->summary(30)['trials_ending']);
    }

    #[Test]
    public function live_active_clients_are_counted_across_organizations_and_demo_or_inactive_ones_are_not(): void
    {
        $this->signInAsPlatform();
        $one = $this->createOrganization()->organization;
        $two = $this->createOrganization()->organization;

        $this->makeClient($one);
        $this->makeClient($one);
        $this->makeClient($one, demo: true);
        $inactive = $this->makeClient($one);
        $this->inTenant($one, fn () => $inactive->forceFill(['status' => 'inactive'])->save());
        $this->makeClient($two);

        $this->assertSame(3, $this->metrics()->summary(30)['live_active_clients']);
    }

    #[Test]
    public function appointments_are_live_only_and_counted_by_start_time_in_the_period(): void
    {
        $this->signInAsPlatform();
        $one = $this->createOrganization()->organization;
        $two = $this->createOrganization()->organization;
        $liveClient = $this->makeClient($one);
        $demoClient = $this->makeClient($one, demo: true);
        $otherClient = $this->makeClient($two);

        $this->makeAppointment($one, $liveClient, now()->subDays(2)->setTime(9, 0));
        $this->makeAppointment($one, $liveClient, now()->subDays(10)->setTime(9, 0));
        $this->makeAppointment($one, $liveClient, now()->subDays(40)->setTime(9, 0));
        $this->makeAppointment($one, $demoClient, now()->subDays(3)->setTime(9, 0));
        $this->makeAppointment($one, $liveClient, now()->addDays(2)->setTime(9, 0));   // not started yet
        $this->makeAppointment($two, $otherClient, now()->subDays(1)->setTime(9, 0));

        $this->assertSame(3, $this->metrics()->summary(30)['appointments_in_period'], 'Two in organization one (2 and 10 days ago) and one in organization two.');
        $this->assertSame(2, $this->metrics()->summary(7)['appointments_in_period']);
        $this->assertSame(4, $this->metrics()->summary(90)['appointments_in_period']);
    }

    #[Test]
    public function live_subscriptions_are_counted_per_plan_in_plan_order_and_ended_ones_are_not(): void
    {
        $admin = $this->signInAsPlatform();
        $this->createOrganization(plan: 'advanced');
        $this->createOrganization(plan: 'starter');
        $this->createOrganization(plan: 'starter');
        $cancelled = $this->createOrganization(plan: 'professional')->organization;
        app(ChangeSubscriptionStatus::class)($cancelled, SubscriptionStatus::Cancelled, $admin, 'Left');

        $plans = $this->metrics()->summary(30)['subscriptions_by_plan'];

        $this->assertSame(
            [['key' => 'starter', 'name' => 'Starter', 'count' => 2], ['key' => 'advanced', 'name' => 'Advanced', 'count' => 1]],
            $plans,
            'Plan order, live subscriptions only: the cancelled one is gone and unused plans do not appear.',
        );
    }

    #[Test]
    public function failed_jobs_are_counted(): void
    {
        $this->signInAsPlatform();
        $this->assertSame(0, $this->metrics()->summary(30)['failed_jobs']);

        foreach (range(1, 3) as $_) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
                'payload' => '{}', 'exception' => 'boom', 'failed_at' => now(),
            ]);
        }

        $this->assertSame(3, $this->metrics()->summary(30)['failed_jobs']);
    }

    #[Test]
    public function recent_activity_is_the_latest_ten_platform_and_system_entries_never_organization_activity(): void
    {
        $this->signInAsPlatform();
        $audit = app(AuditLogger::class);
        foreach (range(1, 12) as $i) {
            $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC')->addMinutes($i));
            $audit->record("platform.test_{$i}", context: $i % 2 === 0 ? AuditContext::Platform : AuditContext::System);
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-02 13:00:00', 'UTC'));
        $audit->record('appointment.cancelled', summary: 'Appointment cancelled for a client', context: AuditContext::Organization);
        $audit->record('portal.signed_in', context: AuditContext::Portal);
        $audit->record('public.inquiry', context: AuditContext::Public);

        $recent = $this->metrics()->summary(30)['recent_audit'];

        $this->assertCount(10, $recent);
        $this->assertSame('platform.test_12', $recent->first()->action, 'Newest first.');
        $this->assertSame('platform.test_3', $recent->last()->action);
        $this->assertEqualsCanonicalizing(['platform', 'system'], $recent->pluck('context')->unique()->values()->all());
        $this->assertFalse($recent->contains(fn ($row) => in_array($row->context, ['organization', 'portal', 'public'], true)));
    }

    #[Test]
    public function the_summary_costs_eight_queries_whatever_the_amount_of_data(): void
    {
        $admin = $this->signInAsPlatform();

        [, $small] = $this->countQueries(fn () => $this->metrics()->summary(30));

        foreach (range(1, 6) as $_) {
            $organization = $this->createOrganization(status: OrganizationStatus::Trial)->organization;
            $client = $this->makeClient($organization);
            $this->makeAppointment($organization, $client, now()->subDays(3));
        }
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);
        app(AuditLogger::class)->record('platform.test', context: AuditContext::Platform);

        [$summary, $large] = $this->countQueries(fn () => $this->metrics()->summary(30));

        $this->assertSame(8, $small, 'Organizations, users, trials, clients, appointments, plans, failed jobs, recent audit.');
        $this->assertSame(8, $large);
        $this->assertSame(6, $summary['organizations']['total']);
    }

    #[Test]
    public function the_cross_tenant_counts_do_not_leave_the_tenant_scope_open(): void
    {
        $this->signInAsPlatform();
        $organization = $this->createOrganization()->organization;
        $this->makeClient($organization);

        $this->metrics()->summary(30);

        $this->assertFalse(app(TenantContext::class)->bypassing(), 'The bypass is closed again.');
        $this->expectException(MissingTenantContext::class);
        Client::query()->count();
    }
}
