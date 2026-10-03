<?php

namespace Tests\Feature\Platform;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Platform\ChangeOrganizationStatus;
use App\Domain\Platform\OrganizationStatus;
use App\Domain\Platform\OrganizationUsage;
use App\Domain\Platform\PlatformMetrics;
use App\Domain\Platform\Queries\OrganizationOverview;
use App\Domain\Platform\Queries\PlatformAuditQuery;
use App\Domain\Platform\Queries\PlatformOrganizationQuery;
use App\Domain\Platform\Queries\PlatformUserQuery;
use App\Domain\Saas\EntitlementReport;
use App\Domain\Saas\SetEntitlementOverride;
use App\Domain\Tenancy\MissingTenantContext;
use App\Domain\Tenancy\TenantContext;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The console is the most privileged surface and must stay blind to tenant
 * records: it may count clients and appointments, never name them. These tests
 * plant a recognisable client (and activity about them) in an organization
 * and prove that nothing a platform read model returns, and no statement it
 * issues, can carry them.
 */
class PlatformPrivacyTest extends PlatformTestCase
{
    private const FIRST = 'Zephyrine';

    private const LAST = 'Quillfeather';

    private const EMAIL = 'zephyrine.quillfeather@patients.example';

    private const PHONE = '+233244777888';

    private const NOTE = 'Needs step-free access';

    /** @return list<string> everything that identifies the planted client */
    private function secrets(): array
    {
        return [self::FIRST, self::LAST, self::EMAIL, self::PHONE, self::NOTE, 'Quillfeather, Zephyrine'];
    }

    private function assertNoClientData(mixed $output, string $where, bool $mayBeEmpty = false): void
    {
        $json = json_encode($output, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);
        if (! $mayBeEmpty) {
            $this->assertGreaterThan(2, strlen($json), "{$where} produced nothing to check.");
        }

        foreach ($this->secrets() as $secret) {
            $this->assertStringNotContainsString($secret, $json, "{$where} must not contain [{$secret}].");
        }
    }

    /** @return array{Organization, Client, User} the organization, its planted client and a staff member who is also on the console */
    private function plant(): array
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization(['name' => 'Privacy Practice'], 'professional')->organization;
        $client = $this->makeClient($organization, [
            'first_name' => self::FIRST, 'last_name' => self::LAST, 'email' => self::EMAIL, 'phone' => self::PHONE, 'administrative_notes' => self::NOTE,
        ]);
        $demo = $this->makeClient($organization, ['first_name' => self::FIRST, 'last_name' => self::LAST.'Demo'], demo: true);
        $this->makeAppointment($organization, $client, now()->subDays(3)->setTime(10, 0));
        $this->makeAppointment($organization, $demo, now()->subDays(2)->setTime(10, 0));

        // Activity inside the organization that names the client, in every context except the platform's own.
        $audit = app(AuditLogger::class);
        foreach ([AuditContext::Organization, AuditContext::Portal, AuditContext::Public] as $context) {
            $audit->record('client.updated', $client, before: ['first_name' => 'Old'], after: ['first_name' => self::FIRST, 'email' => self::EMAIL],
                summary: 'Updated '.self::FIRST.' '.self::LAST, metadata: ['phone' => self::PHONE], context: $context, organizationId: $organization->id);
        }

        // The planted client's own staff colleague is also a platform user (sees the console AND the practice).
        $staff = $this->addStaff($organization, 'clinician', user: $admin)->user;

        return [$organization, $client, $staff];
    }

    #[Test]
    public function the_dashboard_metrics_count_clients_and_appointments_but_never_name_them(): void
    {
        $this->plant();

        $summary = app(PlatformMetrics::class)->summary(90);

        $this->assertSame(1, $summary['live_active_clients'], 'The live client is counted (the demo one is not).');
        $this->assertSame(1, $summary['appointments_in_period'], 'The live appointment is counted (the demo one is not).');
        $this->assertSame(['count', 'next'], array_keys($summary['trials_ending']));
        $this->assertNoClientData($summary, 'PlatformMetrics::summary');
    }

    #[Test]
    public function the_organization_list_and_overview_never_name_a_client(): void
    {
        [$organization] = $this->plant();

        $this->assertNoClientData(app(PlatformOrganizationQuery::class)->paginate()->items(), 'the organization list');
        $this->assertNoClientData(app(PlatformOrganizationQuery::class)->paginate(['q' => 'Privacy'])->items(), 'a searched organization list');

        $overview = app(OrganizationOverview::class)($organization->fresh());
        $this->assertNoClientData($overview, 'the organization overview');
        $this->assertSame(1, $overview->usage['clients']['used'], 'Usage is a count.');
        $this->assertSame(1, $overview->appointmentsLast30Days);

        $this->assertNoClientData((new OrganizationUsage)($organization->fresh()), 'organization usage');
        $this->assertNoClientData(app(EntitlementReport::class)($organization->fresh()), 'the entitlement report');
    }

    #[Test]
    public function the_account_read_models_name_organizations_never_their_clients(): void
    {
        [$organization, , $staff] = $this->plant();

        $this->assertNoClientData(app(PlatformUserQuery::class)->paginate()->items(), 'the account list');
        $this->assertNoClientData(app(PlatformUserQuery::class)->administrators(), 'the administrators list');

        $overview = app(PlatformUserQuery::class)->overview($staff->fresh());
        $this->assertNoClientData($overview, 'an account overview');
        $this->assertSame('Privacy Practice', $overview->memberships[0]['organization'], 'The organization is named; what happens inside it is not.');
    }

    #[Test]
    public function the_audit_log_shows_platform_activity_never_activity_inside_an_organization(): void
    {
        [$organization] = $this->plant();
        $admin = auth()->user();
        app(ChangeOrganizationStatus::class)($organization, OrganizationStatus::Suspended, $admin, 'Unpaid invoice');
        app(SetEntitlementOverride::class)($organization, 'programs', true, null, false, 'Pilot', null, $admin);

        $everything = app(PlatformAuditQuery::class)->paginate([], 100);
        $this->assertNoClientData($everything->items(), 'the audit log');
        $this->assertNotEmpty($everything->items());

        // The rows exist (the tenant's own log has them) but no filter reaches them.
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'client.updated')->count());
        foreach ([['action' => 'client.'], ['actor' => $admin->email], ['context' => 'organization'], ['action' => 'client.updated', 'context' => 'platform']] as $filters) {
            $entries = app(PlatformAuditQuery::class)->paginate($filters, 100)->items();
            $this->assertNoClientData($entries, 'the audit log filtered by '.json_encode($filters), mayBeEmpty: true);
            $this->assertNotContains('client.updated', array_map(fn ($e) => $e->action, $entries));
        }
    }

    #[Test]
    public function every_statement_that_touches_a_tenant_table_is_a_count(): void
    {
        [$organization, , $staff] = $this->plant();
        $admin = auth()->user();

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(PlatformMetrics::class)->summary(90);
        app(PlatformOrganizationQuery::class)->paginate();
        app(OrganizationOverview::class)($organization->fresh());
        app(PlatformUserQuery::class)->paginate();
        app(PlatformUserQuery::class)->overview($staff->fresh());
        app(PlatformUserQuery::class)->administrators();
        app(PlatformAuditQuery::class)->paginate();
        $statements = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $tenantRecords = '/\b(?:from|join)\s+"?(clients|appointments|client_contacts|timeline_entries|appointment_status_histories|services|availability_rules|blocked_times)"?\b/i';
        $touching = $statements->filter(fn (string $sql) => preg_match($tenantRecords, $sql) === 1);

        $this->assertNotEmpty($touching, 'The read models do count clients and appointments, so there must be statements to check.');
        foreach ($touching as $sql) {
            $normalised = strtolower(preg_replace('/\s+/', ' ', $sql));
            $this->assertStringContainsString('count(*)', $normalised, "A statement that reads a tenant table must be a count: {$sql}");
            $this->assertStringNotContainsString('select *', $normalised, "A tenant table must never be read whole: {$sql}");
            foreach (['first_name', 'last_name', 'date_of_birth', 'administrative_notes', 'search_text', 'client_number'] as $column) {
                $this->assertStringNotContainsString($column, $normalised, "A tenant table's {$column} must never be selected: {$sql}");
            }
        }
        $this->assertNotNull($admin);
    }

    #[Test]
    public function reading_the_console_never_leaves_a_tenant_scope_open(): void
    {
        [$organization] = $this->plant();

        app(PlatformMetrics::class)->summary(30);
        app(OrganizationOverview::class)($organization->fresh());

        $this->assertFalse(app(TenantContext::class)->bypassing());
        $this->expectException(MissingTenantContext::class);
        Client::query()->get();
    }

    #[Test]
    public function a_platform_action_leaves_no_client_data_in_its_own_audit_entry(): void
    {
        [$organization] = $this->plant();
        $admin = auth()->user();
        app(ChangeOrganizationStatus::class)($organization, OrganizationStatus::Suspended, $admin, 'Unpaid invoice');
        $this->travelTo(CarbonImmutable::now()->addMinute());

        $platformRows = DB::table('audit_logs')->whereIn('context', ['platform', 'system'])->get();

        $this->assertNotEmpty($platformRows);
        $this->assertNoClientData($platformRows->all(), 'the platform\'s own audit rows');
    }
}
