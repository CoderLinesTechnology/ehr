<?php

namespace Tests\Feature\Clients;

use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationEntitlement;
use App\Models\OrganizationMembership;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Helpers for the clients module's domain tests. The module is exercised
 * through its services and actions the way a controller will call them:
 * inside a tenant, as a member of it, never through HTTP.
 */
abstract class ClientsTestCase extends TestCase
{
    private int $slot = 0;

    /** Run $callback inside $organization as $member: tenant context with their membership, and signed in (the audit actor). */
    protected function actAs(OrganizationMembership $member, Organization $organization, Closure $callback): mixed
    {
        $this->actingAs(User::query()->findOrFail($member->user_id));

        return $this->inTenant($organization, $callback, $member);
    }

    /** A live client created through the factory inside $organization. */
    protected function clientIn(Organization $organization, array $attributes = []): Client
    {
        return $this->inTenant($organization, fn () => Client::factory()->create($attributes));
    }

    protected function demoClientIn(Organization $organization, array $attributes = []): Client
    {
        return $this->inTenant($organization, fn () => Client::factory()->demo()->create($attributes));
    }

    /**
     * An appointment row (the Scheduling module owns the real action; the
     * clients module only reads appointments). Each call takes its own
     * two-hour slot so the clinician-overlap constraint never trips.
     */
    protected function bookAppointment(Organization $organization, Client $client, OrganizationMembership $clinician, string $status = 'completed'): string
    {
        return $this->inTenant($organization, function () use ($organization, $client, $clinician, $status) {
            $service = Service::query()->first() ?? Service::factory()->create();
            $start = CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC')->addHours(++$this->slot * 2);
            $id = (string) Str::uuid7();

            DB::table('appointments')->insert([
                'id' => $id,
                'organization_id' => $organization->id,
                'record_environment' => $client->record_environment->value,
                'client_id' => $client->id,
                'service_id' => $service->id,
                'clinician_membership_id' => $clinician->id,
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $start->addMinutes(50)->toIso8601String(),
                'timezone' => 'Africa/Accra',
                'modality' => 'telehealth',
                'status' => $status,
                'price_minor' => 30000,
                'currency' => 'GHS',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $id;
        });
    }

    /** Override the organization's active-client limit (the plan's own is 150 on Starter). */
    protected function limitActiveClientsTo(Organization $organization, int $limit): void
    {
        OrganizationEntitlement::query()->updateOrCreate(
            ['organization_id' => $organization->id, 'feature_key' => FeatureRegistry::MAX_ACTIVE_CLIENTS],
            ['limit_value' => $limit, 'reason' => 'test'],
        );

        app(EntitlementService::class)->flush();
    }

    /**
     * Run $callback and return [its result, the SQL statements it issued].
     *
     * @return array{0: mixed, 1: list<string>}
     */
    protected function recordingQueries(Closure $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $result = $callback();
            $statements = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return [$result, $statements];
    }

    /** The audit entries of one action, oldest first. */
    protected function auditEntries(string $action): \Illuminate\Support\Collection
    {
        return DB::table('audit_logs')->where('action', $action)->orderBy('occurred_at')->orderBy('id')->get()
            ->map(function ($row) {
                foreach (['before', 'after', 'metadata'] as $column) {
                    $row->$column = $row->$column !== null ? json_decode($row->$column, true) : null;
                }

                return $row;
            });
    }

    protected function organizationRecord(string $id): Organization
    {
        return Organization::query()->findOrFail($id);
    }

    protected function membershipRecord(string $id): OrganizationMembership
    {
        return app(\App\Domain\Tenancy\TenantContext::class)->bypass(fn () => OrganizationMembership::query()->findOrFail($id));
    }
}
